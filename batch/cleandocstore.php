<?php

$arg = getopt("hn:c:Vm:d", array("help", "name:", "count:", "verbose", "match:", "dry-run"));
if (isset($arg["c"]) && !isset($arg["count"]))
    $arg["count"] = $arg["c"];
if (isset($arg["V"]) && !isset($arg["verbose"]))
    $arg["verbose"] = $arg["V"];
if (isset($arg["m"]) && !isset($arg["match"]))
    $arg["match"] = $arg["m"];
if (isset($arg["d"]) && !isset($arg["dry-run"]))
    $arg["dry-run"] = $arg["d"];
if (isset($arg["h"]) || isset($arg["help"])) {
    fwrite(STDOUT, "Usage: php batch/cleandocstore.php [-c COUNT] [-V] [-m MATCH]\n");
    exit(0);
} else if (isset($arg["count"]) && !ctype_digit($arg["count"])) {
    fwrite(STDERR, "Usage: php batch/cleandocstore.php [-c COUNT] [-V] [-m MATCH]\n");
    exit(1);
}

$ConfSitePATH = preg_replace(',/batch/[^/]+,', '', __FILE__);
require_once("$ConfSitePATH/src/init.php");

class Cleaner {
    static public $algo_preg = "(?:sha1|sha256)";
    static public $algo_pfx_preg = "(?:|sha2-)";
    static public $fixed_hash = "";
    static public $hash_preg = "(?:[0-9a-f]{40}|[0-9a-f]{64})";
    static public $extension = null;
    static public $extension_preg = ".*";
    static public $dirinfo = [];

    static function set_match($match) {
        $dot = strpos($match, ".");
        if ($dot !== false) {
            self::$extension = substr($match, $dot);
            self::$extension_preg = preg_quote(self::$extension);
            $match = substr($match, 0, $dot);
        }

        $match = strtolower($match);
        if (!preg_match('{\A(?:sha[123]-?)?(?:[0-9a-f*]|\[\^?[-0-9a-f]+\])*\z}', $match)) {
            fwrite(STDERR, "* bad `--match`, expected `[sha[123]-][0-9a-f*]*`\n");
            exit(1);
        }
        $match_algo = "(?:|sha2-)";
        if (preg_match('{\Asha([12])-?(.*)\z}', $match, $m)) {
            if ($m[1] === "1") {
                self::$algo_preg = "sha1";
                self::$algo_pfx_preg = $match_algo = "";
            } else {
                self::$algo_preg = "sha256";
                self::$algo_pfx_preg = $match_algo = "sha2-";
            }
            $match = $m[2];
        }
        if (preg_match('{\A([0-9a-f]+)}', $match, $m))
            self::$fixed_hash = $m[1];
        if ($match != "")
            self::$hash_preg = str_replace("*", "[0-9a-f]*", $match) . "[0-9a-f]*";
    }

    static function populate($dir, Fparts $fparts, $pos) {
        if ($pos < $fparts->n && $pos % 1 == 0) {
            $dir .= $fparts->components[$pos];
            ++$pos;
        }
        if ($pos >= $fparts->n)
            return 1;
        $di = [];
        $preg = $fparts->pregs[$pos];
        $n = 0;
        $isdir = $pos + 1 < $fparts->n;
        foreach (scandir($dir, SCANDIR_SORT_NONE) as $x) {
            $x = "/$x";
            if ($x !== "/." && $x !== "/.." && preg_match($preg, $x)) {
                $di[] = $n;
                $di[] = $x;
                if ($isdir)
                    $n += self::populate("$dir$x", $fparts, $pos + 1);
                else
                    $n += 1;
            }
        }
        $di[] = $n;
        self::$dirinfo[$dir] = $di;
        return $n;
    }
}

if (!($dp = $Conf->docstore())) {
   fwrite(STDERR, "php batch/cleandocstore.php: Conference doesn't use docstore\n");
   exit(1);
}
$count = isset($arg["count"]) ? intval($arg["count"]) : 10;
$verbose = isset($arg["verbose"]);
$dry_run = isset($arg["dry-run"]);
if (isset($arg["match"]))
    Cleaner::set_match($arg["match"]);

class Fparts {
    public $components = [];
    public $pregs = [];
    public $n;

    public $algo;
    public $hash;
    public $extension;

    function __construct($dp) {
        assert($dp[0] === "/");
        foreach (preg_split("{/+}", $dp) as $fdir)
            if ($fdir !== "") {
                if (preg_match('/%\d*[%hxHjaA]/', $fdir)) {
                    if (count($this->components) % 2 == 0)
                        $this->components[] = "";
                    $this->components[] = "/$fdir";
                } else if (count($this->components) % 2 == 0)
                    $this->components[] = "/$fdir";
                else
                    $this->components[count($this->components) - 1] .= "/$fdir";
            }

        foreach ($this->components as $fp)
            $this->pregs[] = self::make_preg($fp);

        $this->n = count($this->components);
    }
    static function make_preg($entrypat) {
        $preg = "";
        $entrypat = preg_quote($entrypat);
        while ($entrypat !== ""
               && preg_match('{\A(.*?)%(\d*)([%hxHjaA])(.*)\z}', $entrypat, $m)) {
            $preg .= $m[1];
            list($fwidth, $fn, $entrypat) = [$m[2], $m[3], $m[4]];
            if ($fn === "%")
                $preg .= "%";
            else if ($fn === "x")
                $preg .= Cleaner::$extension_preg;
            else if ($fn === "a")
                $preg .= Cleaner::$algo_preg;
            else if ($fn === "A")
                $preg .= Cleaner::$algo_pfx_preg;
            else if ($fn === "j") {
                $l = min(strlen(Cleaner::$fixed_hash), 3);
                $preg .= substr(Cleaner::$fixed_hash, 0, $l);
                for (; $l < 3; ++$l)
                    $preg .= "[0-9a-f]";
                $preg .= "?";
            } else {
                if ($fn === "h")
                    $preg .= Cleaner::$algo_pfx_preg;
                if ($fwidth === "")
                    $preg .= Cleaner::$hash_preg;
                else {
                    $fwidth = intval($fwidth);
                    $l = min(strlen(Cleaner::$fixed_hash), $fwidth);
                    $preg .= substr(Cleaner::$fixed_hash, 0, $l);
                    if ($l < $fwidth)
                        $preg .= "[0-9a-f]{" . ($fwidth - $l) . "}";
                }
            }
        }
        return "{" . $preg . $entrypat . "}";
    }

    function clear() {
        $this->algo = null;
        $this->hash = "";
        $this->extension = null;
    }
    function match_component($text, $i) {
        $match = $this->components[$i];
        $xalgo = $this->algo;
        $xhash = $this->hash;
        $xext = $this->extension;

        $build = "";
        while (preg_match('{\A(.*?)%(\d*)([%hxHjaA])(.*)\z}', $match, $m)) {
            if ($m[1] !== "") {
                if (substr($text, 0, strlen($m[1])) !== $m[1])
                    return false;
                $build .= $m[1];
                $text = substr($text, strlen($m[1]));
            }

            list($fwidth, $fn, $match) = [$m[2], $m[3], $m[4]];
            if ($fn === "%") {
                if (substr($text, 0, 1) !== "%")
                    return false;
                $build .= "%";
                $text = substr($text, 1);
            } else if ($fn === "x") {
                if ($xext !== null) {
                    if (substr($text, 0, strlen($xext)) != $xext)
                        return false;
                    $build .= $xext;
                    $text = substr($text, strlen($xext));
                } else if (preg_match('{\A(\.(?:avi|bin|bz2|csv|docx?|gif|gz|html|jpg|json|mp4|pdf|png|pptx?|ps|tar|tex|txt|xlsx?|zip))}', $text, $m)) {
                    $xext = $m[1];
                    $build .= $m[1];
                    $text = substr($text, strlen($m[1]));
                } else
                    $xext = "";
            } else if ($fn === "j") {
                $l = min(strlen($xhash), 2);
                if (substr($text, 0, $l) !== (string) substr($xhash, 0, $l))
                    return false;
                if (preg_match('{\A([0-9a-f]{2,3})}', $text, $mm)) {
                    if (strlen($mm[1]) > strlen($xhash))
                        $xhash = $mm[1];
                    if (strlen($mm[1]) == 2 && $xalgo === null)
                        $xalgo = "";
                    // XXX don't track that algo *cannot* be SHA-1
                    if (strlen($mm[1]) == 2 ? $xalgo !== "" : $xalgo === "")
                        return false;
                    $build .= $mm[1];
                    $text = substr($text, strlen($mm[1]));
                } else
                    return false;
            } else if ($fn === "a") {
                if (preg_match('{\A(sha1|sha256)}', $text, $mm)) {
                    $malgo = $mm[1] === "sha1" ? "" : "sha2-";
                    if ($xalgo === null)
                        $xalgo = $malgo;
                    if ($xalgo !== $malgo)
                        return false;
                    $build .= $mm[1];
                    $text = substr($text, strlen($mm[1]));
                } else
                    return false;
            } else {
                if ($fn === "A" || $fn === "h") {
                    if ($xalgo !== null) {
                        if ($xalgo !== (string) substr($text, 0, strlen($xalgo)))
                            return false;
                    } else if (preg_match('{\A((?:sha2-)?)}', $text, $mm))
                        $xalgo = $mm[1];
                    else
                        return false;
                    $build .= $xalgo;
                    $text = substr($text, strlen($xalgo));
                    if ($fn === "A")
                        continue;
                }
                if (substr($text, 0, strlen($xhash)) !== $xhash)
                    return false;
                if ($fwidth === "") {
                    if ($xalgo === "")
                        $fwidth = "40";
                    else if ($xalgo === "sha2-")
                        $fwidth = "64";
                    else
                        $fwidth = "40,64";
                }
                if (preg_match('{\A([0-9a-f]{' . $fwidth . '})}', $text, $mm)) {
                    if (strlen($mm[1]) > strlen($xhash))
                        $xhash = $mm[1];
                    $build .= $mm[1];
                    $text = substr($text, strlen($mm[1]));
                } else
                    return false;
            }
        }
        if ((string) $text !== $match) {
            error_log("fail $build, have `$text`, expected `$match`");
            return false;
        }
        $this->algo = $xalgo;
        $this->hash = $xhash;
        $this->extension = $xext;
        return $build . $text;
    }
    function match_complete() {
        return $this->algo !== null
            && strlen($this->hash) === ($this->algo === "" ? 40 : 64);
    }
}

$fparts = new Fparts($dp);
Cleaner::populate("", $fparts, 0);


function random_index($di) {
    global $verbose;
    $l = 0;
    $r = count($di) - 1;
    $val = mt_rand(0, $di[$r] - 1);
    if ($di[$r] == ($r >> 1)) {
        $l = $r = $val << 1;
        //$verbose && error_log("*$val ?{$l}[" . $di[$l] . "," . $di[$l + 2] . ")");
    }
    while ($l + 2 < $r) {
        $m = $l + (($r - $l) >> 1) & ~1;
        //$verbose && error_log("*$val ?{$m}[" . $di[$m] . "," . $di[$m + 2] . ") @[$l,$r)");
        if ($val < $di[$m])
            $r = $m;
        else
            $l = $m;
    }
    return $l;
}

function try_random_match(Fparts $fparts) {
    global $verbose;
    $fparts->clear();
    $bdir = "";
    $bdirs = $idxes = [];

    for ($i = 0; $i < $fparts->n; ++$i)
        if ($i % 2 == 0)
            $bdir .= $fparts->components[$i];
        else {
            if (!isset(Cleaner::$dirinfo[$bdir]))
                return false;
            $di = &Cleaner::$dirinfo[$bdir];
            $ndi = count($di) - 1;
            if ($ndi <= 0)
                break;
            $idx = random_index($di);
            for ($tries = $ndi >> 1; $tries > 0; --$tries) {
                //$verbose && error_log(json_encode([$i, $idx, $di[$idx + 1], $fparts->pregs[$i]]));
                if (($build = $fparts->match_component($di[$idx + 1], $i)))
                    break;
                $idx += 2;
                if ($idx == $ndi)
                    $idx = 0;
            }
            $bdirs[] = $bdir;
            $idxes[] = $idx;
            unset($di);
            $bdir .= $build;
        }

    // account for removal
    $delta = null;
    for ($i = count($idxes) - 1; $i >= 0; --$i) {
        $di = &Cleaner::$dirinfo[$bdirs[$i]];
        $ndi = count($di) - 1;
        $idx = $idxes[$i];
        if ($delta === null)
            $delta = $di[$idx + 2] - $di[$idx];
        if ($delta === $di[$idx + 2] - $di[$idx]) {
            // remove entry
            if ($delta === $di[$ndi] - $di[$ndi - 2])
                $di[$idx + 1] = $di[$ndi - 1];
            else {
                for ($j = $idx + 2; $j < $ndi; $j += 2) {
                    $di[$j - 1] = $di[$j + 1];
                    $di[$j] = $di[$j + 2] - $delta;
                }
            }
            assert($di[$ndi - 2] == $di[$ndi] - $delta);
            array_pop($di);
            array_pop($di);
        } else {
            for ($j = $idx + 2; $j <= $ndi; $j += 2)
                $di[$j] -= $delta;
        }
        unset($di);
    }

    if (!$fparts->match_complete())
        return false;
    return [$fparts->algo . $fparts->hash, $fparts->extension, $bdir];
}


$hotcrpdoc = new HotCRPDocument($Conf, DTYPE_SUBMISSION);
$ndone = $nsuccess = 0;

while ($count > 0) {
    $x = null;
    for ($i = 0; $i < 10000 && !$x; ++$i)
        $x = try_random_match($fparts);
    if (!$x) {
        fwrite(STDERR, "Can't find anything to delete.\n");
        break;
    }
    $doc = new DocumentInfo(["sha1" => $x[0],
                             "mimetype" => Mimetype::type($x[1])]);
    $hashalg = $doc->hash_algorithm();
    $ok = false;
    if ($hashalg === false)
        fwrite(STDERR, "$x[2]: unknown hash\n");
    else if (($chash = hash_file($hashalg, $x[2], true)) === false)
        fwrite(STDERR, "$x[2]: is unreadable\n");
    else if ($chash !== $doc->binary_hash_data())
        fwrite(STDERR, "$x[2]: incorrect hash\n");
    else if ($hotcrpdoc->s3_check($doc)) {
        if ($dry_run) {
            if ($verbose)
                fwrite(STDOUT, "$x[2]: would remove\n");
            $ok = true;
        } else if (unlink($x[2])) {
            if ($verbose)
                fwrite(STDOUT, "$x[2]: removed\n");
            $ok = true;
        } else
            fwrite(STDERR, "$x[2]: cannot remove\n");
    } else
        fwrite(STDERR, "$x[2]: not on S3\n");
    --$count;
    ++$ndone;
    $nsuccess += $ok ? 1 : 0;
}

exit($nsuccess && $nsuccess == $ndone ? 0 : 1);
