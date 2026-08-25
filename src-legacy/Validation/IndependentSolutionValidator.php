<?php
declare(strict_types=1);
namespace Packvium\Validation;
use Packvium\Constraint\{AxleLoad,CompatibilityConstraint,ConstraintContext,LoadCalculator,SupportConstraint,TagCountConstraint};
use Packvium\Constraint\Internal\LoadSupportGraph;
use Packvium\Domain\{AxisAlignedBox,Nesting,PackedContainer,PackingRequest,Placement,Point,UnpackedItem};
use Packvium\Sequence\{RouteSequenceError,UnloadingDependencyGraph};
use Packvium\Unit\Length;
/**
 * Re-derives every guarantee from the placements alone.
 *
 * It shares no state with the solvers, so a solver that reports a placement it never
 * actually verified is caught here rather than reaching the caller.
 */
final class IndependentSolutionValidator
{
    /** @param list<PackedContainer> $containers @param list<UnpackedItem>|null $unpacked */
    public function validate(PackingRequest $request,array $containers,float $minimumSupportRatio=0.0,?Length $clearance=null,?array $unpacked=null):ValidationReport
    {
        $issues=[];$seen=[];$expected=[];$inventory=[];
        $clearanceTicks=(($nullsafeVariable1 = $clearance) ? $nullsafeVariable1->ticks : null)??0;
        foreach($request->instances() as $i)$expected[$i->id()]=true;
        foreach($containers as $packed){
            $inventory[$packed->container->id]=($inventory[$packed->container->id]??0)+1;
            $boundary=new AxisAlignedBox(new Point(0,0,0),$packed->container->innerDimensions);
            $payload=0;
            $placements=$packed->placements;
            $compatibilitySensitive=false;$supportSensitive=$minimumSupportRatio>0;$stackSensitive=false;
            foreach($placements as $placement){
                $item=$placement->instance->item;
                if($item->tags!==[]||$item->incompatibleTags!==[])$compatibilitySensitive=true;
                if($item->minimumSupportRatio>0||($item->groundContactRule!==null&&$item->groundContactRule!=='free'))$supportSensitive=true;
                if(!$item->stackable)$stackSensitive=true;
            }
            $stackGraph=$stackSensitive?new LoadSupportGraph(LoadCalculator::units($placements)):null;
            foreach(self::collisionPairs($placements) as [$left,$right])
                $issues[]=new ValidationIssue('collision',$placements[$left]->instance->id().' with '.$placements[$right]->instance->id());
            foreach($packed->placements as $index=>$p){
                $id=$p->instance->id();
                if(isset($seen[$id]))$issues[]=new ValidationIssue('duplicate_item',$id);
                $seen[$id]=true;
                $payload+=$p->instance->weight()->ticks;
                if(!$boundary->contains($p->envelopeBox()))$issues[]=new ValidationIssue('outside_container',$id);
                if(!in_array($p->rotation,$p->instance->item->allowedRotations,true))$issues[]=new ValidationIssue('forbidden_rotation',$id);
                $expectedDims=$p->instance->item->dimensions->rotated($p->rotation);
                if($p->dimensions->length->ticks!==$expectedDims->length->ticks||$p->dimensions->width->ticks!==$expectedDims->width->ticks||$p->dimensions->height->ticks!==$expectedDims->height->ticks)
                    $issues[]=new ValidationIssue('dimension_mismatch',$id);
                if(!self::envelopeMatches($p,$clearanceTicks))$issues[]=new ValidationIssue('clearance_mismatch',$id);
                foreach($packed->container->obstacles as $o)
                    foreach($o->boxes() as $box)
                        if($p->envelopeBox()->intersects($box))$issues[]=new ValidationIssue('obstacle_collision',$id);
                if($p->instance->item->mustBeOnFloor&&$p->envelopeOrigin->z!==0)
                    $issues[]=new ValidationIssue('must_be_on_floor',$id.': ');
                $eligibleTags=$p->instance->item->eligibleContainerTags;
                if($eligibleTags!==[]&&array_intersect($eligibleTags,$packed->container->tags)===[])
                    $issues[]=new ValidationIssue('container_ineligible',$id);
                // The common lattice case has no pair-dependent rules. Avoid building
                // an O(n) "all other placements" array for every item in that case.
                $ctx=null;
                if($compatibilitySensitive||$supportSensitive){
                    $others=array_merge(array_slice($placements,0,$index),array_slice($placements,$index+1));
                    $ctx=new ConstraintContext($packed->container,$others,$p->instance,$p->envelopeOrigin,$p->rotation,$p->dimensions,$p->envelopeDimensions);
                }
                $constraints=[];
                if($compatibilitySensitive){$constraints[]=new CompatibilityConstraint();$constraints[]=new TagCountConstraint();}
                if($supportSensitive)$constraints[]=new SupportConstraint($minimumSupportRatio);
                foreach($constraints as $constraint){
                    $r=$constraint->evaluate($ctx);
                    if(!$r->allowed)$issues[]=new ValidationIssue($r->code,$id.': '.$r->detail);
                }
                if($stackSensitive){
                    $failure=$stackGraph->nonStackableFailure($placements,$p->instance,$index);
                    if($failure!==null)$issues[]=new ValidationIssue($failure[0],$id.': '.$failure[1]);
                }
            }
            // A second, whole-container bearing pass: the per-placement check above
            // reports the first offender it meets, this one is anchored on the container.
            $units=LoadCalculator::units($packed->placements);
            $densityLimit=($nullsafeVariable2 = $packed->container->maxStackDensity) ? $nullsafeVariable2->ticks : null;
            $failure=LoadCalculator::overloaded($units)??LoadCalculator::stackLimitExceeded($units)??LoadCalculator::stackDensityExceeded($units,$densityLimit);
            if($failure!==null)$issues[]=new ValidationIssue($failure[0],$packed->id().': '.$failure[1]);
            if($packed->container->axles!==null){
                $axleFailure=AxleLoad::exceeded(
                    $packed->container->axles,
                    $units,
                    $packed->container->tareWeight->ticks,
                    $packed->container->innerDimensions->length->ticks,
                );
                if($axleFailure!==null)$issues[]=new ValidationIssue($axleFailure[0],$packed->id().': '.$axleFailure[1]);
            }
            $onRoute=false;foreach($placements as $p)if($p->instance->item->stopIndex!==null){$onRoute=true;break;}
            if($onRoute){
                $routeIssue=self::unloadingOrderViolation($packed);
                if($routeIssue!==null)$issues[]=$routeIssue;
            }
            if($packed->container->maxPayload!==null&&$payload>$packed->container->maxPayload->ticks)$issues[]=new ValidationIssue('payload_exceeded',$packed->id());
            if($packed->container->maxItems!==null&&count($packed->placements)>$packed->container->maxItems)$issues[]=new ValidationIssue('max_items_exceeded',$packed->id());
        }
        foreach($request->containers as $c)
            if($c->quantity!==null&&($inventory[$c->id]??0)>$c->quantity)$issues[]=new ValidationIssue('container_inventory_exceeded',$c->id);
        if($unpacked!==null){
            foreach($unpacked as $item){
                $id=$item->instance->id();
                if(isset($seen[$id]))$issues[]=new ValidationIssue('duplicate_item',$id.' is both packed and unpacked');
                $seen[$id]=true;
                if($item->reason==='')$issues[]=new ValidationIssue('missing_reason',$id);
            }
            $missing=[];
            foreach($expected as $id=>$_)if(!isset($seen[$id]))$missing[]=$id;
            sort($missing);
            foreach($missing as $id)$issues[]=new ValidationIssue('missing_item',$id);
        }
        $unknown=[];
        foreach($seen as $id=>$_)if(!isset($expected[$id]))$unknown[]=$id;
        sort($unknown);
        foreach($unknown as $id)$issues[]=new ValidationIssue('unknown_item',$id);
        foreach(self::splitGroups($containers) as $detail)$issues[]=new ValidationIssue('group_split',$detail);
        if($unpacked!==null)
            foreach(self::partialGroups($request,$containers) as $detail)$issues[]=new ValidationIssue('group_partial',$detail);
        return new ValidationReport($issues===[],$issues);
    }

    /**
     * @param list<Placement> $placements @return list<array{0:int,1:int}>
     *
     * A valid nested pair is excluded: it is a deliberate, exactly bounded
     * overlap between two identical items, one sunk into the other, not a collision.
     *
     * Pruning the sweep's active set by x-overlap alone -- as a single sweep over
     * every placement did previously -- leaves a large multi-layer lattice (many
     * z-levels that all share similar x ranges) with an active set that stays close
     * to O(n), turning the sweep into O(n * activeSize) instead of the intended
     * near-linear behaviour. Bucketing by z-level first, mirroring
     * ContactGraph's ContactLevelIndex, bounds each bucket's sweep by that level's own
     * density instead of the whole container's.
     */
    private static function collisionPairs(array $placements):array
    {
        if($placements===[])return [];
        $cell=1;
        foreach($placements as $placement){
            $box=$placement->envelopeBox();
            $cell=max($cell,$box->z2()-$box->origin->z);
        }
        $buckets=[];
        foreach($placements as $index=>$placement){
            $box=$placement->envelopeBox();
            $zCells=array_unique([intdiv($box->origin->z,$cell),intdiv($box->z2()-1,$cell)]);
            foreach($zCells as $zCell)$buckets[$zCell][]=$index;
        }
        $pairs=[];
        foreach($buckets as $indices){
            $ordered=[];
            foreach($indices as $index){
                $box=$placements[$index]->envelopeBox();
                $ordered[]=[$box->origin->x,$box->x2(),$index,$box];
            }
            usort($ordered,static function (array $a, array $b): int {
                return [$a[0],$a[1],$a[2]]<=>[$b[0],$b[1],$b[2]];
            });
            $active=[];
            foreach($ordered as [$x1,$x2,$index,$box]){
                $active=array_values(array_filter($active,static function (array $entry) use ($x1): bool {
                    return $entry[0]>$x1;
                }));
                foreach($active as [,$other,$otherBox])
                    if($box->intersects($otherBox)&&!Nesting::isValidNesting($placements[$index],$placements[$other]))
                        $pairs[min($index,$other).':'.max($index,$other)]=[min($index,$other),max($index,$other)];
                $active[]=[$x2,$index,$box];
            }
        }
        $pairs=array_values($pairs);
        usort($pairs,static function (array $a, array $b): int {
            return $a<=>$b;
        });
        return $pairs;
    }

    /**
     * Whether this container's route can actually be unloaded stop by
     * stop, using the same geometry `UnloadingDependencyGraph::safeRouteRemovalOrder`
     * already proves safe removal orders against -- no separate notion of "blocked"
     * for this check to disagree with the one the Sequence module uses.
     *
     * Physical, not envelope, boxes: reachability is about what a forklift or hand
     * would actually collide with, and clearance is a solver placement margin, not a
     * real obstruction.
     */
    private static function unloadingOrderViolation(PackedContainer $packed):?ValidationIssue
    {
        $placements=$packed->placements;
        $boxes=array_map(static function (Placement $p) {
            return $p->box();
        },$placements);
        $stops=array_map(static function (Placement $p) {
            return $p->instance->item->stopIndex;
        },$placements);
        try{
            UnloadingDependencyGraph::safeRouteRemovalOrder($boxes,$stops,$packed->container->innerDimensions);
        }catch(RouteSequenceError $error){
            $stuckIds=array_map(static function (int $i) use ($placements) {
                return $placements[$i]->instance->id();
            },$error->stuck);
            sort($stuckIds);
            return new ValidationIssue(
                'unloading_order_violation',
                $packed->id().': stop '.$error->stop.' cannot be fully unloaded ('.implode(', ',$stuckIds).' still blocked)',
            );
        }
        return null;
    }

    private static function envelopeMatches(Placement $p,int $clearanceTicks):bool
    {
        $expected=$clearanceTicks?$p->dimensions->expand(new Length($clearanceTicks)):$p->dimensions;
        return $p->envelopeDimensions->length->ticks===$expected->length->ticks
            &&$p->envelopeDimensions->width->ticks===$expected->width->ticks
            &&$p->envelopeDimensions->height->ticks===$expected->height->ticks
            &&$p->position->x===$p->envelopeOrigin->x+$clearanceTicks
            &&$p->position->y===$p->envelopeOrigin->y+$clearanceTicks
            &&$p->position->z===$p->envelopeOrigin->z+$clearanceTicks;
    }

    /** @param list<PackedContainer> $containers @return list<string> */
    private static function splitGroups(array $containers):array
    {
        $located=[];
        foreach($containers as $packed)
            foreach($packed->placements as $placement){
                $group=$placement->instance->item->group;
                if($group===null)continue;
                $located[$group][$packed->id()]=true;
            }
        ksort($located);
        $out=[];
        foreach($located as $group=>$where){
            if(count($where)<=1)continue;
            $ids=array_keys($where);sort($ids);
            $out[]=$group.': '.implode(', ',$ids);
        }
        return $out;
    }

    /** @param list<PackedContainer> $containers @return list<string> */
    private static function partialGroups(PackingRequest $request,array $containers):array
    {
        $expected=[];$placed=[];
        foreach($request->instances() as $instance){
            $group=$instance->item->group;
            if($group!==null)$expected[$group][$instance->id()]=true;
        }
        foreach($containers as $packed)
            foreach($packed->placements as $placement){
                $group=$placement->instance->item->group;
                if($group!==null)$placed[$group][$placement->instance->id()]=true;
            }
        ksort($expected);$out=[];
        foreach($expected as $group=>$members){
            $count=count($placed[$group]??[]);
            if($count>0&&$count<count($members))$out[]=$group.': '.$count.'/'.count($members).' packed';
        }
        return $out;
    }
}
