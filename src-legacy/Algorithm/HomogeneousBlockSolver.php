<?php
declare(strict_types=1);
namespace Packvium\Algorithm;

use Packvium\Config\PackingConfig;
use Packvium\Config\SolverProfile;
use Packvium\Constraint\Internal\LoadSupportGraph;
use Packvium\Constraint\PlacementConstraint;
use Packvium\Domain\{AxisAlignedBox,Container,Dimensions,ItemInstance,Placement,Point,ShapeType};
use Packvium\Extension\CandidateScorer;
use Packvium\Support\BigInt;
use Packvium\Unit\Length;

/**
 * Deterministic solid single-type block search for unrestricted carton loading.
 *
 * Candidate enumeration is O(B*S*T*R*X*Y) time and O(S+n) space. B is committed
 * blocks, S the capped maximal-space count, T declared item types, R unique
 * rotations, and X/Y the block grid extents. Both Deadline and
 * containerPlanNodeLimit bound the product explicitly. The X*Y lattice nodes of a
 * group are counted in O(X), and only a group that can still lead walks its nodes,
 * so the X*Y factor is the worst case, not the usual one.
 */
final class HomogeneousBlockSolver implements SingleContainerSolver
{
    /**
     * @var list<PlacementConstraint>
     */
    private $constraints = [];
    /**
     * @var list<string>
     * @readonly
     */
    public $modes = self::MODES;
    /**
     * @readonly
     * @var int|null
     */
    public $nodeLimit;
    public const MODES=['count','volume'];
    /** Far above the 4-ulp error of a float product of three lengths and a count. */
    private const FLOAT_MARGIN=1e-12;
    /** Lattice nodes between two clock reads are a power of two: 1 << CLOCK_SHIFT. */
    private const CLOCK_SHIFT=6;
    private const CLOCK_STRIDE=(1<<self::CLOCK_SHIFT)-1;
    /** Leading blocks the `quality` lookahead completes in each round. */
    public const LOOKAHEAD_WIDTH=4;
    /** The lookahead's lattice-node budget per mode, as a multiple of the node limit. */
    public const LOOKAHEAD_NODE_FACTOR=64;
    /**
     * Lattice nodes one mode may enumerate under every profile but `quality` -- the bound
     * `quality` gives the same solver through `containerPlanNodeLimit`.
     */
    public const START_NODE_LIMIT=100000;

    /**
     * Every profile but `quality` leaves `containerPlanNodeLimit` at one, which would stop
     * the lattice enumeration before its first block, so under them the solver carries its
     * own bound (`nodeLimit()`); the start `fast` and `balanced` add passes it.
     *
     * @param list<PlacementConstraint> $constraints
     * @param list<string> $modes
     */
    public function __construct(array $constraints=[], ?CandidateScorer $scorer=null, array $modes=self::MODES, ?int $nodeLimit=null)
    {
        $this->constraints = $constraints;
        $this->modes = $modes;
        $this->nodeLimit = $nodeLimit;
    }

    public function name():string{return 'homogeneous_blocks';}
    public function orderInsensitive():bool{return true;}

    public function packOne(Container $container,int $sequence,array $items,PackingConfig $config,SearchStats $stats,Deadline $deadline):SingleContainerSolution
    {
        if(!$this->supports($container,$items,$config))
            return (new ExtremePointSolver($this->constraints))->packOne($container,$sequence,$items,$config,$stats,$deadline);
        $nodeLimit=$this->nodeLimit($config);
        $best=null;
        foreach($this->modes as $mode){
            if($deadline->expired())break;
            $solution=$this->packMode($container,$sequence,$items,$config,$stats,$deadline,$mode,$nodeLimit);
            if($config->profile===SolverProfile::Quality&&!$deadline->expired())
                $solution=$this->lookahead($container,$sequence,$items,$config,$stats,$deadline,$mode,$nodeLimit*self::LOOKAHEAD_NODE_FACTOR,$solution);
            $byVolume=$config->objective==='maximum_volume';
            if($best===null||$this->solutionKey($solution,$byVolume)<$this->solutionKey($best,$byVolume))$best=$solution;
        }
        // Only the deadline or the effort budget marks the answer as stopped by a limit: the
        // node limit is a search bound, and the answer it leaves is the search's own.
        if($best===null)return new SingleContainerSolution(new ContainerState($container,$sequence),$items,false,$deadline->stopped());
        return new SingleContainerSolution($best->state,$best->unpacked,false,$deadline->stopped());
    }

    /** Lattice nodes one mode may enumerate: the start's own bound, `quality`'s node limit, or START_NODE_LIMIT. */
    private function nodeLimit(PackingConfig $config):int
    {
        if($this->nodeLimit!==null)return $this->nodeLimit;
        return $config->profile===SolverProfile::Quality?$config->containerPlanNodeLimit:self::START_NODE_LIMIT;
    }

    public function supports(Container $container,array $items,PackingConfig $config):bool
    {
        // A block set on a smaller one overhangs it, so a request that asks for support
        // is left to the per-item search.
        if($config->minimumSupportRatio>0)return false;
        if($this->constraints!==[]||$container->obstacles!==[]||$container->preloaded!==[]||$container->axles!==null||$container->tagLimits!==[]||$container->maxStackDensity!==null||$container->voidFillReserveRatio>0||$container->isFlatBag())return false;
        foreach($items as $instance){
            $item=$instance->item;
            if($item->shapeType!==ShapeType::RIGID_CUBOID||$item->group!==null||$item->tags!==[]||$item->incompatibleTags!==[]||$item->eligibleContainerTags!==[]||!$item->stackable||$item->mustBeOnFloor||$item->maxTopLoad!==null||$item->maxStackedItems!==null||$item->minimumSupportRatio!=0.0||!in_array($item->groundContactRule,[null,'free'],true)||$item->nestingHeight!==null||$item->stopIndex!==null)return false;
            // Block-on-block contacts would need a second implementation of the stacking-group
            // rule; the general solver already walks every edge.
            if($item->acceptsStackingGroups!==[])return false;
        }
        return true;
    }

    /** The share of a member's base that rests on the floor or on a box beneath it. */
    private static function memberSupport(ContainerState $state,ItemInstance $instance,Point $point,Dimensions $envelope):float
    {
        if($point->z===0)return 1.0;
        $support=LoadSupportGraph::candidateView($state->placements,$instance,new AxisAlignedBox($point,$envelope));
        return $support->supportingArea/$envelope->baseAreaTicks();
    }

    private function solutionKey(SingleContainerSolution $solution,bool $byVolume=false):array
    {
        $signature=[];
        foreach($solution->state->placements as $placement)$signature[]=$placement->instance->id().'@'.$placement->envelopeOrigin->x.','.$placement->envelopeOrigin->y.','.$placement->envelopeOrigin->z;
        $descendingUsed=array_map(static function (int $chunk): int {
            return -$chunk;
        },BigInt::chunks($solution->state->usedVolume));
        if($byVolume)return array_merge($descendingUsed, [count($solution->unpacked), $solution->state->maxZ, implode('|',$signature)]);
        return array_merge([count($solution->unpacked)], $descendingUsed, [$solution->state->maxZ, implode('|',$signature)]);
    }

    /**
     * The distinct rotations of one item type with what every lattice of them shares.
     *
     * @return list<array{rotation:\Packvium\Domain\Rotation,rotationName:string,physical:Dimensions,envelope:Dimensions,extents:array{int,int,int},volume:string,approximateVolume:float}>
     */
    private static function lattices(ItemInstance $prototype,PackingConfig $config):array
    {
        $lattices=[];
        foreach($prototype->dimensions()->uniqueRotations($prototype->item->allowedRotations) as [$rotation,$physical]){
            $envelope=$config->clearance->ticks?$physical->expand($config->clearance):$physical;
            $lattices[]=['rotation'=>$rotation,'rotationName'=>$rotation,'physical'=>$physical,'envelope'=>$envelope,'extents'=>[$envelope->length->ticks,$envelope->width->ticks,$envelope->height->ticks],'volume'=>$physical->volumeString(),'approximateVolume'=>$physical->volumeScore()];
        }
        return $lattices;
    }

    /**
     * Whether a block ranks strictly before the incumbent under the exact key.
     *
     * The key is the lead -- count, fill of its space in parts per million, then exact used
     * volume under `count`; volume, fill, then count otherwise -- and then the anchor,
     * type, rotation and extents. Fields are compared one at a time, each with `<=>` as
     * the whole-array comparison would, so a decimal-string volume is only built when the
     * float volumes cannot separate the pair.
     *
     * @param array<string,mixed> $candidate
     * @param array<string,mixed> $best
     */
    private static function leads(array $candidate,array $best,string $mode):bool
    {
        if($mode==='count'){
            if($candidate['count']!==$best['count'])return $candidate['count']>$best['count'];
            $order=(self::fillPpm($best)<=>self::fillPpm($candidate))?:self::volumeOrder($candidate,$best);
        }else{
            $order=self::volumeOrder($candidate,$best)?:(self::fillPpm($best)<=>self::fillPpm($candidate))?:($best['count']<=>$candidate['count']);
        }
        if($order===0){
            $mine=$candidate['space']->origin;$theirs=$best['space']->origin;
            $order=($mine->z<=>$theirs->z)?:($mine->y<=>$theirs->y)?:($mine->x<=>$theirs->x)
                ?:($candidate['itemId']<=>$best['itemId'])?:($candidate['rotationName']<=>$best['rotationName'])
                ?:($candidate['nx']<=>$best['nx'])?:($candidate['ny']<=>$best['ny'])?:($candidate['nz']<=>$best['nz']);
        }
        return $order<0;
    }

    /**
     * -1 when the candidate uses strictly more volume, the order of the descending volume key.
     *
     * A float volume carries a relative error below 4e-16, so one apart by the margin is
     * apart exactly; only a closer pair builds both decimal-string volumes.
     *
     * @param array<string,mixed> $candidate
     * @param array<string,mixed> $best
     */
    private static function volumeOrder(array $candidate,array $best):int
    {
        if($candidate['approximateUsed']>$best['approximateUsed']*(1+self::FLOAT_MARGIN))return -1;
        if($candidate['approximateUsed']<$best['approximateUsed']*(1-self::FLOAT_MARGIN))return 1;
        return BigInt::compare(BigInt::multiply($best['count'],$best['volume']),BigInt::multiply($candidate['count'],$candidate['volume']));
    }

    /**
     * floor(used * 1e6 / space volume), the block's fill of its space in parts per million.
     *
     * The float quotient is at most 1e6 and carries a relative error near 1e-15, so its
     * floor is exact unless it lies within 1e-6 of an integer; only then is the
     * decimal-string long division run.
     *
     * @param array<string,mixed> $block
     */
    private static function fillPpm(array $block):int
    {
        $space=$block['space']->dimensions;
        $quotient=$block['approximateUsed']*1000000/$space->volumeScore();
        $floor=floor($quotient);
        if($quotient-$floor>self::FLOAT_MARGIN*1000000&&$floor+1-$quotient>self::FLOAT_MARGIN*1000000)return (int)$floor;
        return (int)BigInt::divide(BigInt::multiply(BigInt::multiply($block['count'],$block['volume']),1000000),$space->volumeString());
    }

    /**
     * The lattice nodes of one group: sum over nx <= min(X, c) of min(Y, floor(c / nx)).
     *
     * Every row up to floor(c / Y) is Y nodes wide, so only the rows past it are summed:
     * O(min(X, c)) against the group's O(X * Y) nodes.
     */
    private static function latticeNodes(int $maximumX,int $maximumY,int $capacity):int
    {
        $maximumNx=min($maximumX,$capacity);
        $fullRows=min($maximumNx,intdiv($capacity,$maximumY));
        $nodes=$fullRows*$maximumY;
        for($nx=$fullRows+1;$nx<=$maximumNx;$nx++)$nodes+=intdiv($capacity,$nx);
        return $nodes;
    }

    /**
     * How many of a group's nodes expand before a stop, the count the per-node loop reached.
     *
     * `$stopAt` is the node a counted limit trips at. The clock is read once, on the first
     * node of the group at a 64-node stride, and a stop there lands on that node: only where
     * a wall-clock stop lands can move, never a counted one.
     */
    private static function expandable(int $nodes,int $groupNodes,int $stopAt,Deadline $deadline):int
    {
        $expandable=min($groupNodes,$stopAt-$nodes);
        $clockRead=($nodes+self::CLOCK_STRIDE)&~self::CLOCK_STRIDE;
        if($clockRead<$nodes+$expandable&&$deadline->expired())return $clockRead-$nodes;
        return $expandable;
    }

    /**
     * The group's first largest block in (nx, ny) order, as [count, nx, ny, nz].
     *
     * For a capacity below the whole lattice: no node exceeds the capacity, so the first one
     * to reach it ends the walk. (With room for the whole lattice, the whole lattice is the
     * only node of its count.)
     *
     * @return array{int,int,int,int}
     */
    private static function largestBlock(int $maximumX,int $maximumY,int $maximumZ,int $capacity):array
    {
        $count=0;$bestNx=0;$bestNy=0;$bestNz=0;
        $maximumNx=$maximumX<$capacity?$maximumX:$capacity;
        for($nx=1;$nx<=$maximumNx;$nx++){
            $maximumNy=intdiv($capacity,$nx);
            if($maximumY<$maximumNy)$maximumNy=$maximumY;
            for($ny=1;$ny<=$maximumNy;$ny++){
                $nz=intdiv($capacity,$nx*$ny);
                if($maximumZ<$nz)$nz=$maximumZ;
                if($nx*$ny*$nz<=$count)continue;
                $count=$nx*$ny*$nz;$bestNx=$nx;$bestNy=$ny;$bestNz=$nz;
                if($count===$capacity)return [$count,$bestNx,$bestNy,$bestNz];
            }
        }
        return [$count,$bestNx,$bestNy,$bestNz];
    }

    /**
     * The round's `$leaders` leading blocks over every (space, type, rotation) group, best first.
     *
     * Every group's nodes count toward the limits before any is ranked, and a stop inside a
     * group lands on the node the per-node loop stopped at: a stopped round places nothing,
     * so which node led it is never read. Inside one group every key field except the
     * count-derived lead is equal, and fill and volume grow with the count, so the group's
     * first largest count is the only node that can lead. No node exceeds the capacity or
     * the whole lattice, and once `$leaders` blocks are kept a group whose bound already
     * trails the last of them cannot lead: the cheap lead is monotone in the count, since a
     * float product is.
     *
     * @param list<Space> $spaces
     * @param array<string,int> $capacities
     * @param array<string,list<array<string,mixed>>> $rotations
     * @return list<array<string,mixed>>
     */
    private static function leadingBlocks(array $spaces,array $capacities,array $rotations,string $mode,int $leaders,int &$nodes,int $stopAt,SearchStats $stats,Deadline $deadline,bool &$reached):array
    {
        $leading=[];$byCount=$mode==='count';
        // The last kept block's cheap lead as scalars: its count, and its float volume less the margin.
        $leadCount=0;$leadFloor=-INF;
        foreach($spaces as $space){
            $spaceLength=$space->dimensions->length->ticks;$spaceWidth=$space->dimensions->width->ticks;$spaceHeight=$space->dimensions->height->ticks;
            foreach($capacities as $itemId=>$capacity){
                foreach($rotations[$itemId] as $lattice){
                    [$envelopeLength,$envelopeWidth,$envelopeHeight]=$lattice['extents'];
                    if($envelopeLength>$spaceLength||$envelopeWidth>$spaceWidth)continue;
                    $maximumX=intdiv($spaceLength,$envelopeLength);
                    $maximumY=intdiv($spaceWidth,$envelopeWidth);
                    $groupNodes=$maximumX*$maximumY<=$capacity?$maximumX*$maximumY:self::latticeNodes($maximumX,$maximumY,$capacity);
                    // Only a group that reaches a counted limit or a clock read needs the slow path.
                    if($nodes+$groupNodes>$stopAt||(($nodes+$groupNodes-1)>>self::CLOCK_SHIFT)!==(($nodes-1)>>self::CLOCK_SHIFT)){
                        $expanded=self::expandable($nodes,$groupNodes,$stopAt,$deadline);
                        $nodes+=$expanded;$stats->searchNodesExpanded+=$expanded;
                        if($expanded<$groupNodes){$deadline->effortStops();$reached=true;return [];}
                    }else{$nodes+=$groupNodes;$stats->searchNodesExpanded+=$groupNodes;}
                    if($envelopeHeight>$spaceHeight)continue;
                    $maximumZ=intdiv($spaceHeight,$envelopeHeight);
                    $whole=$maximumX*$maximumY*$maximumZ;
                    $volume=$lattice['approximateVolume'];
                    $largest=$capacity<$whole?$capacity:$whole;
                    if($byCount?$largest<$leadCount:$largest*$volume<$leadFloor)continue;
                    [$count,$nx,$ny,$nz]=$capacity>=$whole?[$whole,$maximumX,$maximumY,$maximumZ]:self::largestBlock($maximumX,$maximumY,$maximumZ,$capacity);
                    $approximateUsed=$count*$volume;
                    if($byCount?$count<$leadCount:$approximateUsed<$leadFloor)continue;
                    $candidate=['space'=>$space,'itemId'=>$itemId,'rotation'=>$lattice['rotation'],'rotationName'=>$lattice['rotationName'],'physical'=>$lattice['physical'],'envelope'=>$lattice['envelope'],'volume'=>$lattice['volume'],'approximateUsed'=>$approximateUsed,'nx'=>$nx,'ny'=>$ny,'nz'=>$nz,'count'=>$count];
                    $full=count($leading)===$leaders;
                    if($full&&!self::leads($candidate,$leading[$leaders-1],$mode))continue;
                    $position=count($leading);
                    while($position>0&&self::leads($candidate,$leading[$position-1],$mode))$position--;
                    array_splice($leading,$position,0,[$candidate]);
                    if(count($leading)>$leaders)array_pop($leading);
                    if(count($leading)===$leaders){$last=$leading[$leaders-1];$leadCount=$last['count'];$leadFloor=$last['approximateUsed']*(1-self::FLOAT_MARGIN);}
                }
            }
        }
        return $leading;
    }

    /** @param list<ItemInstance> $items @return array<string,list<ItemInstance>> */
    private static function byType(array $items):array
    {
        $remaining=[];
        foreach($items as $instance)$remaining[$instance->item->id][]=$instance;
        ksort($remaining,SORT_STRING);
        return $remaining;
    }

    /**
     * Each type's distinct rotations with what every lattice of them shares.
     *
     * @param array<string,list<ItemInstance>> $remaining
     * @return array<string,list<array<string,mixed>>>
     */
    private static function rotationsOf(array $remaining,PackingConfig $config):array
    {
        $rotations=[];
        foreach($remaining as $itemId=>$available)$rotations[$itemId]=self::lattices($available[0],$config);
        return $rotations;
    }

    private function packMode(Container $container,int $sequence,array $items,PackingConfig $config,SearchStats $stats,Deadline $deadline,string $mode,int $nodeLimit):SingleContainerSolution
    {
        $remaining=self::byType($items);
        $nodes=0;$reached=false;
        $solution=$this->complete($container,new ContainerState($container,$sequence),[new Space(new Point(0,0,0),$container->innerDimensions)],$remaining,self::rotationsOf($remaining,$config),$config,$stats,$deadline,$mode,$nodeLimit,$nodes,$reached);
        return new SingleContainerSolution($solution->state,$solution->unpacked,false,$reached||$deadline->stopped());
    }

    /**
     * How many of each type one block may still take, for the types that may take any.
     *
     * @param array<string,list<ItemInstance>> $remaining
     * @return array<string,int>
     */
    private static function capacities(Container $container,ContainerState $state,array $remaining):array
    {
        $capacities=[];
        foreach($remaining as $itemId=>$available){
            if($available===[])continue;
            $prototype=$available[0];$capacity=count($available);
            if($container->maxItems!==null)$capacity=min($capacity,$container->maxItems-$state->placementCount());
            if($container->maxPayload!==null&&$prototype->weight()->ticks>0)$capacity=min($capacity,intdiv($container->maxPayload->ticks-$state->payloadTicks,$prototype->weight()->ticks));
            if($capacity<=0)continue;
            $capacities[$itemId]=$capacity;
        }
        return $capacities;
    }

    /**
     * One round's leading blocks under the mode's node limit and the effort budget.
     *
     * @param list<Space> $spaces
     * @param array<string,list<ItemInstance>> $remaining
     * @param array<string,list<array<string,mixed>>> $rotations
     * @return list<array<string,mixed>>
     */
    private static function round(Container $container,ContainerState $state,array $spaces,array $remaining,array $rotations,string $mode,int $leaders,int $nodeLimit,int &$nodes,SearchStats $stats,Deadline $deadline,bool &$reached):array
    {
        // Only the node count moves a counted limit inside one round: candidates and
        // placements are counted when a block is placed, after it.
        $stopAt=$nodes+max(0,min($nodeLimit-$nodes,$deadline->searchNodesLeft($stats)));
        return self::leadingBlocks($spaces,self::capacities($container,$state,$remaining),$rotations,$mode,$leaders,$nodes,$stopAt,$stats,$deadline,$reached);
    }

    /**
     * Commit each round's leading block until nothing more fits.
     *
     * @param list<Space> $spaces
     * @param array<string,list<ItemInstance>> $remaining
     * @param array<string,list<array<string,mixed>>> $rotations
     */
    private function complete(Container $container,ContainerState $state,array $spaces,array $remaining,array $rotations,PackingConfig $config,SearchStats $stats,Deadline $deadline,string $mode,int $nodeLimit,int &$nodes,bool &$reached):SingleContainerSolution
    {
        while($spaces!==[]&&array_sum(array_map('count',$remaining))>0){
            $leading=self::round($container,$state,$spaces,$remaining,$rotations,$mode,1,$nodeLimit,$nodes,$stats,$deadline,$reached);
            if($leading===[]||$reached)break;
            $spaces=self::commit($state,$spaces,$remaining,$leading[0],$config,$stats,$deadline);
            if($spaces===null){$reached=true;break;}
        }
        $unpacked=[];foreach($remaining as $available)foreach($available as $instance)$unpacked[]=$instance;
        return new SingleContainerSolution($state,$unpacked,false,$reached);
    }

    /**
     * `$greedy`, or a better answer from completing each round's leading blocks.
     *
     * Each round ranks its LOOKAHEAD_WIDTH leading blocks, completes each one greedily on a
     * copy, and commits the block whose completion ranks best. The greedy answer is the first
     * completion, so the result is never worse than it. The round's first block is the greedy
     * choice after the block committed last, so its completion is the one already known --
     * unless the greedy pass was stopped by the node limit, when the first round completes it
     * under the lookahead's larger budget. When that budget runs out the best completion so
     * far stands; only the deadline or the effort budget marks the answer as stopped.
     *
     * @param list<ItemInstance> $items
     */
    private function lookahead(Container $container,int $sequence,array $items,PackingConfig $config,SearchStats $stats,Deadline $deadline,string $mode,int $nodeLimit,SingleContainerSolution $greedy):SingleContainerSolution
    {
        $byVolume=$config->objective==='maximum_volume';
        $state=new ContainerState($container,$sequence);
        $spaces=[new Space(new Point(0,0,0),$container->innerDimensions)];
        $remaining=self::byType($items);
        $rotations=self::rotationsOf($remaining,$config);
        $best=$greedy;$bestKey=$this->solutionKey($greedy,$byVolume);
        $known=$greedy->timeLimitReached?null:$greedy;
        $nodes=0;$reached=false;
        while($spaces!==[]&&array_sum(array_map('count',$remaining))>0){
            $leading=self::round($container,$state,$spaces,$remaining,$rotations,$mode,self::LOOKAHEAD_WIDTH,$nodeLimit,$nodes,$stats,$deadline,$reached);
            if($leading===[]||$reached)break;
            $chosen=$known===null?null:[$this->solutionKey($known,$byVolume),$known,$leading[0]];
            foreach($known===null?$leading:array_slice($leading,1) as $block){
                $trialState=$state->copy();$trialRemaining=$remaining;
                $trialSpaces=self::commit($trialState,$spaces,$trialRemaining,$block,$config,$stats,$deadline);
                if($trialSpaces===null){$reached=true;break;}
                $trial=$this->complete($container,$trialState,$trialSpaces,$trialRemaining,$rotations,$config,$stats,$deadline,$mode,$nodeLimit,$nodes,$reached);
                if($reached)break;
                $trialKey=$this->solutionKey($trial,$byVolume);
                if($chosen===null||$trialKey<$chosen[0])$chosen=[$trialKey,$trial,$block];
            }
            if($reached||$chosen===null)break;
            [$chosenKey,$known,$block]=$chosen;
            if($chosenKey<$bestKey){$best=$known;$bestKey=$chosenKey;}
            $spaces=self::commit($state,$spaces,$remaining,$block,$config,$stats,$deadline);
            if($spaces===null){$reached=true;break;}
        }
        return new SingleContainerSolution($best->state,$best->unpacked,false,$best->timeLimitReached||$deadline->stopped());
    }

    /**
     * Place every member of the block, bottom layer first, and return the spaces left.
     *
     * Each member is one counted candidate and placement. When the effort budget has room for
     * fewer than the whole block, the members it has room for are placed, the budget records the
     * stop, and `null` says the search ends here: a counter never passes its limit.
     *
     * @param list<Space> $spaces
     * @param array<string,list<ItemInstance>> $remaining
     * @param array<string,mixed> $best
     * @return list<Space>|null
     */
    private static function commit(ContainerState $state,array $spaces,array &$remaining,array $best,PackingConfig $config,SearchStats $stats,Deadline $deadline):?array
    {
        [$attemptCeiling,$candidateCeiling]=$deadline->placementCeilings($stats);
        $members=max(0,min($best['count'],$attemptCeiling-$stats->placementsAttempted,$candidateCeiling-$stats->candidatesEvaluated));
        self::placeMembers($state,$remaining,$best,$members,$config,$stats);
        if($members<$best['count']){
            $deadline->effortStops();
            return null;
        }
        $blockDimensions=new Dimensions(new Length($best['nx']*$best['envelope']->length->ticks),new Length($best['ny']*$best['envelope']->width->ticks),new Length($best['nz']*$best['envelope']->height->ticks));
        return MaximalSpaceSolver::subtractAll($spaces,[new AxisAlignedBox($best['space']->origin,$blockDimensions)],$stats);
    }

    /**
     * The block's first `$members` members, x fastest, then y, then z: a layer is filled before
     * the one above it, so a member above the bottom layer rests on the member beneath it.
     *
     * @param array<string,list<ItemInstance>> $remaining
     * @param array<string,mixed> $best
     */
    private static function placeMembers(ContainerState $state,array &$remaining,array $best,int $members,PackingConfig $config,SearchStats $stats):void
    {
        $chosen=array_slice($remaining[$best['itemId']],0,$members);
        $remaining[$best['itemId']]=array_slice($remaining[$best['itemId']],$members);
        $clearance=$config->clearance->ticks;$perLayer=$best['nx']*$best['ny'];
        for($index=0;$index<$members;$index++){
            $x=$index%$best['nx'];$y=intdiv($index,$best['nx'])%$best['ny'];$z=intdiv($index,$perLayer);
            $point=new Point($best['space']->origin->x+$x*$best['envelope']->length->ticks,$best['space']->origin->y+$y*$best['envelope']->width->ticks,$best['space']->origin->z+$z*$best['envelope']->height->ticks);
            $position=new Point($point->x+$clearance,$point->y+$clearance,$point->z+$clearance);
            // Above its bottom layer a member rests on an identical footprint; the
            // bottom layer rests on whatever is there.
            $support=$z===0?self::memberSupport($state,$chosen[$index],$point,$best['envelope']):1.0;
            $state->addDirect(new Placement($chosen[$index],$position,$best['rotation'],$best['physical'],$point,$best['envelope'],$support));
            $stats->candidatesEvaluated++;$stats->placementsAttempted++;
        }
    }
}
