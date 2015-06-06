<?php
// mincostmaxflow.php -- HotCRP min-cost max-flow
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class MinCostMaxFlow_Node {
    public $name;
    public $vindex;
    public $klass;
    public $flow = 0;
    public $link = null;
    public $linkrev = null;
    public $xlink = null;
    public $npos = null;
    public $cycle = null;
    public $distance = 0;
    public $excess = 0;
    public $price = 0;
    public $n_outgoing_admissible = 0;
    public $e = array();
    public function __construct($name, $klass) {
        $this->name = $name;
        $this->klass = $klass;
    }
    public function check_excess($expected) {
        foreach ($this->e as $e)
            $expected += $e->flow_to($this);
        if ($expected != $this->excess)
            fwrite(STDERR, "{$this->name}: bad excess e{$this->excess}, have $expected\n");
        assert($expected == $this->excess);
    }
    public function count_outgoing_price_admissible() {
        $n = 0;
        foreach ($this->e as $e)
            if ($e->is_price_admissible_from($this))
                ++$n;
        return $n;
    }
};

class MinCostMaxFlow_Edge {
    public $src;
    public $dst;
    public $cap;
    public $cost;
    public $flow = 0;
    public function __construct($src, $dst, $cap, $cost) {
        $this->src = $src;
        $this->dst = $dst;
        $this->cap = $cap;
        $this->cost = $cost;
    }
    public function other($v) {
        return $v === $this->src ? $this->dst : $this->src;
    }
    public function flow_to($v) {
        return $v === $this->dst ? $this->flow : -$this->flow;
    }
    public function residual_cap($isrev) {
        return $isrev ? $this->flow : $this->cap - $this->flow;
    }
    public function residual_cap_from($v) {
        return $v === $this->src ? $this->cap - $this->flow : $this->flow;
    }
    public function residual_cap_to($v) {
        return $v === $this->src ? $this->flow : $this->cap - $this->flow;
    }
    public function is_distance_admissible_from($v) {
        if ($v === $this->src)
            return $this->flow < $this->cap && $v->distance == $this->dst->distance + 1;
        else
            return $this->flow > 0 && $v->distance == $this->src->distance + 1;
    }
    public function is_price_admissible_from($v) {
        $c = $this->cost + $this->src->price - $this->dst->price;
        if ($v === $this->src)
            return $this->flow < $this->cap && $c < 0;
        else
            return $this->flow > 0 && $c > 0;
    }
};

class MinCostMaxFlow {
    private $v = array();
    private $vmap;
    private $source;
    private $sink;
    private $e = array();
    private $maxflow = null;
    private $maxcap;
    private $mincost;
    private $maxcost;
    private $progressf = array();
    private $hasrun;
    private $debug;
    // times
    public $maxflow_start_at = null;
    public $maxflow_end_at = null;
    public $mincost_start_at = null;
    public $mincost_end_at = null;
    // pushrelabel/cspushrelabel state
    private $epsilon;
    private $ltail;

    const PMAXFLOW = 0;
    const PMAXFLOW_DONE = 1;
    const PMINCOST_BEGINROUND = 2;
    const PMINCOST_INROUND = 3;
    const PMINCOST_DONE = 4;

    const CSPUSHRELABEL_ALPHA = 12;

    const DEBUG = 1;

    public function __construct($flags = false) {
        $this->clear();
        $this->debug = ($flags & self::DEBUG) != 0;
    }

    public function add_node($name, $klass) {
        if ($name === "")
            $name = ".v" . count($this->v);
        assert(is_string($name) && !isset($this->vmap[$name]));
        $v = new MinCostMaxFlow_Node($name, $klass);
        $this->v[] = $this->vmap[$name] = $v;
        return $v;
    }

    public function add_edge($vs, $vd, $cap, $cost = 0) {
        if (is_string($vs))
            $vs = $this->vmap[$vs];
        if (is_string($vd))
            $vd = $this->vmap[$vd];
        assert(($vs instanceof MinCostMaxFlow_Node) && ($vd instanceof MinCostMaxFlow_Node));
        assert($vs !== $this->sink && $vd !== $this->source && $vs !== $vd);
        // XXX assert(this edge does not exist)
        $this->e[] = new MinCostMaxFlow_Edge($vs, $vd, $cap, $cost);
        $this->maxcap = max($this->maxcap, $cap);
        $this->mincost = min($this->mincost, $cost);
        $this->maxcost = max($this->maxcost, $cost);
    }

    public function add_progressf($progressf) {
        $this->progressf[] = $progressf;
    }


    // extract information

    public function nodes($klass) {
        $a = array();
        foreach ($this->v as $v)
            if ($v->klass === $klass)
                $a[] = $v;
        return $a;
    }

    public function current_flow() {
        if ($this->maxflow !== null)
            return $this->maxflow;
        else
            return min(-$this->source->excess, $this->sink->excess);
    }

    public function has_excess() {
        foreach ($this->v as $v)
            if ($v->excess)
                return true;
        return false;
    }

    public function current_excess() {
        $n = -($this->source->excess + $this->sink->excess);
        foreach ($this->v as $v)
            $n += $v->excess;
        return $n;
    }

    public function current_cost() {
        $cost = 0;
        foreach ($this->e as $e)
            if ($e->flow)
                $cost += $e->flow * $e->cost;
        return $cost;
    }

    private function add_reachable($v, $klass, &$a) {
        if ($v->klass === $klass)
            $a[] = $v;
        else if ($v !== $this->sink) {
            foreach ($v->e as $e)
                if ($e->src === $v && $e->flow > 0)
                    $this->add_reachable($e->dst, $klass, $a);
        }
    }

    public function reachable($v, $klass) {
        if (is_string($v))
            $v = $this->vmap[$v];
        $a = array();
        $this->add_reachable($v, $klass, $a);
        return $a;
    }


    // internals

    private function initialize_edges() {
        foreach ($this->e as $e)
            $e->src->e[] = $e;
        foreach ($this->e as $e)
            $e->dst->e[] = $e;
    }


    // Cycle canceling via Bellman-Ford (very slow)

    private function bf_walk($v) {
        $e = $v->link;
        if ($e === null)
            return array(null, null, null, null);
        else if (!$v->linkrev)
            return array($e, false, $e->src);
        else
            return array($e, true, $e->dst);
    }

    private function cyclecancel_iteration() {
        // initialize
        foreach ($this->v as $v) {
            $v->distance = INF;
            $v->link = $v->linkrev = $v->cycle = null;
        }
        $this->source->distance = 0;

        // run Bellman-Ford algorithm
        $more = true;
        for ($iter = 1; $more && $iter < count($this->v); ++$iter) {
            $more = false;
            foreach ($this->e as $i => $e) {
                if ($e->flow < $e->cap) {
                    $xdist = $e->src->distance + $e->cost;
                    if ($e->dst->distance > $xdist) {
                        $e->dst->distance = $xdist;
                        $e->dst->link = $e;
                        $e->dst->linkrev = false;
                        $more = true;
                    }
                }
                if ($e->flow) {
                    $xdist = $e->dst->distance - $e->cost;
                    if ($e->src->distance > $xdist) {
                        $e->src->distance = $xdist;
                        $e->src->link = $e;
                        $e->src->linkrev = true;
                        $more = true;
                    }
                }
            }
        }

        // saturate minimum negative-cost cycles, which must be disjoint
        $any_cycles = false;
        foreach ($this->v as $vi => $v) {
            $xv = $v;
            while ($xv !== null && $xv->cycle === null) {
                $xv->cycle = $v;
                list($e, $erev, $xv) = $this->bf_walk($xv);
            }
            if ($xv !== null && $xv->cycle === $v) {
                $yv = $xv;
                // find available capacity
                $cap = INF;
                do {
                    list($e, $erev, $yv) = $this->bf_walk($yv);
                    $cap = min($cap, $e->residual_cap($erev));
                } while ($yv !== $xv);
                // saturate
                do {
                    list($e, $erev, $yv) = $this->bf_walk($yv);
                    $e->flow += $erev ? -$cap : $cap;
                } while ($yv !== $xv);
                $any_cycles = true;
            }
        }

        return $any_cycles;
    }

    private function cyclecancel_run() {
        // make it a circulation
        $this->e[] = new MinCostMaxFlow_Edge($this->sink, $this->source, count($this->e) * $this->maxcap, -count($this->v) * ($this->maxcost + 1));

        while ($this->cyclecancel_iteration())
            /* nada */;

        array_pop($this->e);
        foreach ($this->e as $i => $e)
            if ($e->flow > 0)
                $e->src->flow += $e->flow;
    }


    // push-relabel: maximum flow only, ignores costs

    private static function pushrelabel_bfs_setdistance($qtail, $v, $dist) {
        $v->distance = $dist;
        $qtail->xlink = $v;
        return $v;
    }

    private function pushrelabel_make_distance() {
        foreach ($this->v as $v) {
            $v->distance = 0;
            $v->xlink = null;
        }
        $qhead = $qtail = $this->sink;
        while ($qhead) {
            $d = $qhead->distance + 1;
            foreach ($qhead->e as $e)
                if ($e->residual_cap_to($qhead) > 0
                    && $e->other($qhead)->distance === 0)
                    $qtail = self::pushrelabel_bfs_setdistance($qtail, $e->other($qhead), $d);
            $qhead = $qhead->xlink;
        }
    }

    private function pushrelabel_push_from($e, $src) {
        $amt = min($src->excess, $e->residual_cap_from($src));
        $amt = ($src == $e->dst ? -$amt : $amt);
        //fwrite(STDERR, "push {$amt} {$e->src->name}@{$e->src->distance} -> {$e->dst->name}@{$e->dst->distance}\n");
        $e->flow += $amt;
        $e->src->excess -= $amt;
        $e->dst->excess += $amt;
    }

    private function pushrelabel_relabel($v) {
        $d = INF;
        foreach ($v->e as $e)
            if ($e->residual_cap_from($v) > 0)
                $d = min($d, $e->other($v)->distance + 1);
        //fwrite(STDERR, "relabel {$v->name}@{$v->distance}->{$d}\n");
        $v->distance = $d;
        $v->npos = 0;
    }

    private function pushrelabel_discharge($v) {
        $ne = count($v->e);
        $notrelabeled = 1;
        while ($v->excess > 0) {
            if ($v->npos == $ne) {
                $this->pushrelabel_relabel($v);
                $notrelabeled = 0;
            } else {
                $e = $v->e[$v->npos];
                if ($e->is_distance_admissible_from($v))
                    $this->pushrelabel_push_from($e, $v);
                else
                    ++$v->npos;
            }
        }
        return !$notrelabeled;
    }

    private function pushrelabel_run() {
        $this->maxflow_start_at = microtime(true);

        // initialize preflow
        $this->pushrelabel_make_distance();
        foreach ($this->source->e as $e) {
            $e->flow = $e->cap;
            $e->dst->excess += $e->cap;
            $e->src->excess -= $e->cap;
        }

        // initialize lists and neighbor position
        $lhead = $ltail = null;
        foreach ($this->v as $v) {
            $v->npos = 0;
            if ($v !== $this->source && $v !== $this->sink) {
                $ltail ? ($ltail->link = $v) : ($lhead = $v);
                $v->link = null;
                $ltail = $v;
            }
        }

        // relabel-to-front
        $n = 0;
        $l = $lhead;
        $lprev = null;
        $max_distance = 2 * count($this->v) - 1;
        $nrelabels = 0;
        while ($l) {
            // check progress
            ++$n;
            if ($n % 32768 == 0)
                foreach ($this->progressf as $progressf)
                    call_user_func($progressf, $this, self::PMAXFLOW);

            // discharge current vertex
            if ($this->pushrelabel_discharge($l)) {
                ++$nrelabels;
                // global relabeling heuristic is quite useful
                if ($nrelabels % count($this->v) == 0)
                    $this->pushrelabel_make_distance();
                if ($l !== $lhead) {
                    $lprev->link = $l->link;
                    $l->link = $lhead;
                    $lhead = $l;
                }
            }
            assert($l->distance <= $max_distance);
            $lprev = $l;
            $l = $l->link;

            // thanks to global relabeling heuristic, may still have active
            // nodes; go one more time through
            if (!$l) {
                $lprev = null;
                for ($l = $lhead; $l && $l->excess == 0; ) {
                    $lprev = $l;
                    $l = $l->link;
                }
            }
        }

        $this->maxflow = $this->sink->excess;
        $this->source->excess = $this->sink->excess = 0;
        $this->maxflow_end_at = microtime(true);
        foreach ($this->progressf as $progressf)
            call_user_func($progressf, $this, self::PMAXFLOW_DONE);
    }


    // cost-scaling push-relabel

    private function cspushrelabel_push_from($e, $src) {
        $dst = $e->other($src);
        // push lookahead heuristic
        if ($dst->excess >= 0 && !$dst->n_outgoing_admissible) {
            $this->debug && fwrite(STDERR, "push lookahead {$src->name} > {$dst->name}\n");
            $this->cspushrelabel_relabel($dst);
            return;
        }

        $amt = min($src->excess, $e->residual_cap_from($src));
        $amt = ($e->src === $src ? $amt : -$amt);
        $e->flow += $amt;
        $e->src->excess -= $amt;
        $e->dst->excess += $amt;
        if (!$e->residual_cap_from($src))
            --$src->n_outgoing_admissible;

        if ($dst->excess > 0 && $dst->link === false) {
            $this->ltail = $this->ltail->link = $dst;
            $dst->link = null;
        }
        $this->debug && fwrite(STDERR, "push $amt {$e->src->name} > {$e->dst->name}\n");
    }

    private function cspushrelabel_relabel($v) {
        // calculate new price
        $p = -INF;
        foreach ($v->e as $e)
            if ($e->src === $v && $e->flow < $e->cap)
                $p = max($p, $e->dst->price - $e->cost - $this->epsilon);
            else if ($e->dst === $v && $e->flow > 0)
                $p = max($p, $e->src->price + $e->cost - $this->epsilon);
        $p_delta = $p > -INF ? $p - $v->price : -$this->epsilon;
        $v->price += $p_delta;
        $this->debug && fwrite(STDERR, "relabel {$v->name} E{$v->excess} @" . ($v->price - $p_delta) . "->{$v->price}\n");

        // start over on arcs
        $v->npos = 0;

        // adjust n_outgoing_admissible counts
        foreach ($v->e as $e) {
            $c = $e->cost + $e->src->price - $e->dst->price;
            $old_c = $c + ($e->src === $v ? -$p_delta : $p_delta);
            if (($c < 0) !== ($old_c < 0) && $e->flow < $e->cap)
                $e->src->n_outgoing_admissible += $c < 0 ? 1 : -1;
            if (($c > 0) !== ($old_c > 0) && $e->flow > 0)
                $e->dst->n_outgoing_admissible += $c > 0 ? 1 : -1;
        }
    }

    private function cspushrelabel_discharge($v) {
        $ne = count($v->e);
        $notrelabeled = 1;
        while ($v->excess > 0) {
            if ($v->npos == $ne) {
                $this->cspushrelabel_relabel($v);
                $notrelabeled = 0;
            } else {
                $e = $v->e[$v->npos];
                if ($e->is_price_admissible_from($v))
                    $this->cspushrelabel_push_from($e, $v);
                else
                    ++$v->npos;
            }
        }
        return !$notrelabeled;
    }

    private function cspushrelabel_refine($phaseno, $nphases) {
        foreach ($this->progressf as $progressf)
            call_user_func($progressf, $this, self::PMINCOST_BEGINROUND, $phaseno, $nphases);

        $this->epsilon = $this->epsilon / self::CSPUSHRELABEL_ALPHA;

        // saturate negative-cost arcs
        foreach ($this->v as $v)
            if ($v->n_outgoing_admissible) {
                foreach ($v->e as $e)
                    if ($e->is_price_admissible_from($v)) {
                        $delta = ($e->src === $v ? $e->cap : 0) - $e->flow;
                        $e->flow += $delta;
                        $e->dst->excess += $delta;
                        $e->src->excess -= $delta;
                        --$v->n_outgoing_admissible;
                    }
            }

        // initialize lists and neighbor position
        $lhead = $this->ltail = null;
        foreach ($this->v as $v) {
            $v->npos = 0;
            if ($v->excess > 0) {
                $this->ltail ? ($this->ltail->link = $v) : ($lhead = $v);
                $v->link = null;
                $this->ltail = $v;
            } else
                $v->link = false;
        }

        // relabel-to-front
        $n = 0;
        while ($lhead) {
            // check progress
            ++$n;
            if ($n % 1024 == 0)
                foreach ($this->progressf as $progressf)
                    call_user_func($progressf, $this, self::PMINCOST_INROUND, $phaseno, $nphases);

            // discharge current vertex
            $this->cspushrelabel_discharge($lhead);
            $l = $lhead->link;
            $lhead->link = false;
            $lhead = $l;
        }
    }

    public function cspushrelabel_finish() {
        // refine the maximum flow to achieve min cost
        $this->mincost_start_at = microtime(true);
        $phaseno = $nphases = 0;
        for ($e = $this->epsilon; $e >= 1 / count($this->v); $e /= self::CSPUSHRELABEL_ALPHA)
            ++$nphases;

        foreach ($this->v as $v)
            $v->n_outgoing_admissible = $v->count_outgoing_price_admissible();

        while ($this->epsilon >= 1 / count($this->v)) {
            $this->cspushrelabel_refine($phaseno, $nphases);
            ++$phaseno;
        }
        $this->mincost_end_at = microtime(true);

        foreach ($this->progressf as $progressf)
            call_user_func($progressf, $this, self::PMINCOST_DONE, $phaseno, $nphases);
    }


    public function shuffle() {
        // shuffle vertices and edges because edge order affects which
        // circulations we choose; this randomizes the assignment
        shuffle($this->v);
        shuffle($this->e);
    }

    public function run() {
        assert(!$this->hasrun);
        $this->hasrun = true;
        $this->initialize_edges();
        $this->pushrelabel_run();
        if ($this->mincost != 0 || $this->maxcost != 0) {
            $this->epsilon = $this->maxcost;
            $this->cspushrelabel_finish();
        }
    }


    public function reset() {
        if ($this->hasrun) {
            foreach ($this->v as $v) {
                $v->distance = $v->excess = $v->price = 0;
                $v->e = array();
            }
            foreach ($this->e as $e)
                $e->flow = 0;
            $this->maxflow = null;
            $this->maxflow_start_at = $this->maxflow_end_at = null;
            $this->mincost_start_at = $this->mincost_end_at = null;
            $this->hasrun = false;
        }
    }

    public function clear() {
        // break circular references
        foreach ($this->v as $v)
            $v->link = $v->xlink = $v->cycle = $v->e = null;
        foreach ($this->e as $e)
            $e->src = $e->dst = null;
        $this->v = array();
        $this->e = array();
        $this->vmap = array();
        $this->maxflow = null;
        $this->maxcap = $this->mincost = $this->maxcost = 0;
        $this->source = $this->add_node(".source", ".internal");
        $this->sink = $this->add_node(".sink", ".internal");
        $this->hasrun = false;
    }

    public function debug_info($only_flow = false) {
        $ex = array();
        $cost = 0;
        foreach ($this->e as $e) {
            if ($e->flow || !$only_flow)
                $ex[] = "{$e->src->name} {$e->dst->name} $e->cap $e->cost $e->flow\n";
            if ($e->flow)
                $cost += $e->flow * $e->cost;
        }
        sort($ex);
        $vx = array();
        foreach ($this->v as $v)
            if ($v->excess)
                $vx[] = "E {$v->name} {$v->excess}\n";
        sort($vx);
        $x = "";
        if ($this->hasrun)
            $x = "total {$e->flow} $cost\n";
        return $x . join("", $ex) . join("", $vx);
    }


    private function dimacs_input($mincost) {
        $x = array("p " . ($mincost ? "min" : "max") . " "
                   . count($this->v) . " " . count($this->e) . "\n");
        foreach ($this->v as $i => $v)
            $v->vindex = $i + 1;
        if ($mincost && $this->maxflow) {
            $x[] = "n {$this->source->vindex} {$this->maxflow}\n";
            $x[] = "n {$this->sink->vindex} -{$this->maxflow}\n";
        } else {
            $x[] = "n {$this->source->vindex} s\n";
            $x[] = "n {$this->sink->vindex} t\n";
        }
        foreach ($this->v as $v)
            if ($v !== $this->source && $v !== $this->sink)
                $x[] = "c ninfo {$v->vindex} {$v->name} {$v->klass}\n";
        if ($mincost) {
            foreach ($this->e as $e)
                $x[] = "a {$e->src->vindex} {$e->dst->vindex} 0 {$e->cap} {$e->cost}\n";
        } else {
            foreach ($this->e as $e)
                $x[] = "a {$e->src->vindex} {$e->dst->vindex} {$e->cap}\n";
        }
        return join("", $x);
    }

    public function maxflow_dimacs_input() {
        return $this->dimacs_input(false);
    }

    public function mincost_dimacs_input() {
        return $this->dimacs_input(true);
    }


    private function dimacs_output($mincost) {
        $x = array("c p " . ($mincost ? "min" : "max") . " "
                   . count($this->v) . " " . count($this->e) . "\n");
        foreach ($this->v as $i => $v)
            $v->vindex = $i + 1;
        if ($mincost) {
            $x[] = "s " . $this->current_cost() . "\n";
            $x[] = "c min_epsilon " . $this->epsilon . "\n";
            foreach ($this->v as $v)
                if ($v->price != 0)
                    $x[] = "c nprice {$v->vindex} {$v->price}\n";
        } else
            $x[] = "s " . $this->current_flow() . "\n";
        foreach ($this->e as $e)
            if ($e->flow) {
                // is this flow ambiguous?
                $n = 0;
                foreach ($e->src->e as $ee)
                    if ($ee->dst === $e->dst)
                        ++$n;
                if ($n !== 1)
                    $x[] = "c finfo {$e->cap} {$e->cost}\n";
                $x[] = "f {$e->src->vindex} {$e->dst->vindex} {$e->flow}\n";
            }
        return join("", $x);
    }

    public function maxflow_dimacs_output() {
        return $this->dimacs_output(false);
    }

    public function mincost_dimacs_output() {
        return $this->dimacs_output(true);
    }


    private function dimacs_node(&$vnames, $num, $name = "", $klass = "") {
        if (!($v = @$vnames[$num]))
            $v = $vnames[$num] = $this->add_node($name, $klass);
        return $v;
    }

    public function parse_dimacs($str) {
        $this->reset();
        $vnames = array();
        $ismax = null;
        $next_cap = $next_cost = null;
        $has_edges = false;
        foreach (CsvParser::split_lines($str) as $lineno => $line) {
            if ($line[0] !== "f")
                $next_cap = $next_cost = null;
            if (preg_match('/\An (\d+) (-?\d+|s|t)\s*\z/', $line, $m)) {
                $issink = $m[2] === "t" || $m[2] < 0;
                assert(!@$vnames[$m[1]]);
                $vnames[$m[1]] = $v = $issink ? $this->sink : $this->source;
                if ($m[2] !== "s" && $m[2] !== "t") {
                    $v->excess = (int) $m[2];
                    $this->maxflow = abs($v->excess);
                }
            } else if (preg_match('/\Ac ninfo (\d+) (\S+)\s*(\S*)\s*\z/', $line, $m)) {
                $this->dimacs_node($vnames, $m[1], $m[2], $m[3]);
            } else if (preg_match('/\Ac nprice (\d+) (\S+)\s*\z/', $line, $m)
                       && is_numeric($m[2])) {
                $v = $this->dimacs_node($vnames, $m[1]);
                $v->price = (float) $m[2];
            } else if (preg_match('/\Aa (\d+) (\d+) (\d+)\s*\z/', $line, $m)) {
                assert(!$has_edges);
                $this->add_edge($this->dimacs_node($vnames, $m[1]),
                                $this->dimacs_node($vnames, $m[2]),
                                (int) $m[3], 0);
            } else if (preg_match('/\Aa (\d+) (\d+) 0 (\d+) (-?\d+)\s*\z/', $line, $m)) {
                assert(!$has_edges);
                $this->add_edge($this->dimacs_node($vnames, $m[1]),
                                $this->dimacs_node($vnames, $m[2]),
                                (int) $m[3], (int) $m[4]);
            } else if (preg_match('/\Ac finfo (\d+)\s*(|-?\d+)\s*\z/', $line, $m)) {
                $next_cap = (int) $m[1];
                $next_cost = (int) $m[2];
            } else if (preg_match('/\Af (\d+) (\d+) (-?\d+)\s*\z/', $line, $m)) {
                if (!$has_edges) {
                    $this->initialize_edges();
                    $has_edges = true;
                }
                $src = $this->dimacs_node($vnames, $m[1]);
                $dst = $this->dimacs_node($vnames, $m[2]);
                $found = false;
                foreach ($src->e as $e)
                    if ($e->dst === $dst
                        && ($next_cap === null || $e->cap === $next_cap)
                        && ($next_cost === null || $e->cost === $next_cost)) {
                        $e->flow = (int) $m[3];
                        $src->excess -= $e->flow;
                        $dst->excess += $e->flow;
                        $found = true;
                        break;
                    }
                if (!$found)
                    error_log("MinCostMaxFlow::parse_dimacs: line " . ($lineno + 1) . ": no such edge");
                $next_cap = $next_cost = null;
            } else if (preg_match('/\As (\d+)\s*\z/', $line, $m)
                       && $this->source->excess === 0) {
                $this->source->excess = -(int) $m[1];
                $this->sink->excess = (int) $m[1];
                $this->maxflow = (int) $m[1];
            } else if (preg_match('/\Ac min_epsilon (\S+)\s*\z/', $line, $m)
                       && is_numeric($m[1])) {
                $this->epsilon = (float) $m[1];
            } else if ($line[0] === "a" || $line[0] === "f") {
                error_log("MinCostMaxFlow::parse_dimacs: line " . ($lineno + 1) . ": parse error");
            }
        }
        ksort($vnames, SORT_NUMERIC);
        $this->v = array_values($vnames);
    }
}
