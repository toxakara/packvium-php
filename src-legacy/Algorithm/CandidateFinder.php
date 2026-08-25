<?php
declare(strict_types=1);
namespace Packvium\Algorithm;
use Packvium\Config\PackingConfig;
use Packvium\Constraint\{AxleLoad,ConstraintContext,LoadCalculator,PlacementConstraint,SupportConstraint};
use Packvium\Support\{BigInt,StableSorter};
use Packvium\Domain\{ItemInstance,Nesting,Placement,Point};
use Packvium\Extension\CandidateScorer;
final class CandidateFinder
{
    /**
     * Feasible placements of $item, best first.
     *
     * @param list<PlacementConstraint> $constraints
     * @param list<Point>|null $points Restrict the scan to these origins; null scans the state's own points.
     * @return list<Candidate>
     */
    public static function find(ContainerState $state,ItemInstance $item,PackingConfig $config,array $constraints,SearchStats $stats,Deadline $deadline,CandidateScorer $scorer,?int $max=null,?array $points=null):array
    {
        $container=$state->container;
        if($container->maxItems!==null&&count($state->placements)>=$container->maxItems)return [];
        if($container->maxPayload!==null&&$state->payloadTicks+$item->weight()->ticks>$container->maxPayload->ticks)return [];
        if($container->voidFillReserveRatio>0&&$item->item->nestingHeight===null){
            $projected=BigInt::add($state->usedVolume,$item->dimensions()->volumeString());
            if(BigInt::compare($projected,$state->usableVolume())>0)return [];
        }
        $inner=$container->innerDimensions;
        $limitX=$inner->length->ticks;$limitY=$inner->width->ticks;$limitZ=$inner->height->ticks;
        $clearance=$config->clearance->ticks;
        // Envelope and extents depend only on the rotation, so they are built once
        // instead of once per (point, rotation) pair.
        $forms=[];
        foreach($item->dimensions()->uniqueRotations($item->item->allowedRotations) as [$rotation,$physical]){
            $envelope=$clearance?$physical->expand($config->clearance):$physical;
            $forms[]=[$rotation,$physical,$envelope,$envelope->length->ticks,$envelope->width->ticks,$envelope->height->ticks];
        }
        $placed=$state->placements;
        $stackSensitive=$state->stackSensitive||!$item->item->stackable||$item->item->maxTopLoad!==null||$item->item->maxStackedItems!==null||$container->maxStackDensity!==null;
        $routeSensitive=$state->routeSensitive||$item->item->stopIndex!==null;
        $bounds=$state->bounds;
        $index=$state->index;
        if($points===null){
            if($item->item->nestingHeight===null)$scan=array_slice($state->orderedPoints,0,$config->maxCandidatePoints);
            else{
                $merged=[];
                foreach(array_merge($state->orderedPoints, self::nestingPoints($state,$item)) as $candidatePoint)
                    $merged["{$candidatePoint->x}:{$candidatePoint->y}:{$candidatePoint->z}"]=$candidatePoint;
                $scan=array_values($merged);
                usort($scan,static function (Point $a, Point $b): int {
                    return [$a->z,$a->y,$a->x]<=>[$b->z,$b->y,$b->x];
                });
                $scan=array_slice($scan,0,$config->maxCandidatePoints);
            }
            if($container->axles!==null)$scan=array_merge($scan, self::axleBalancedPoints($state,$item,$forms,$limitX));
        }else{$scan=$points;usort($scan,static function (Point $a, Point $b): int {
            return [$a->z,$a->y,$a->x]<=>[$b->z,$b->y,$b->x];
        });}
        $out=[];$best=null;
        $placementOffset=count($bounds)-count($placed);
        foreach($scan as $point){
            $deadline->check();
            $stats->candidatePointsConsidered++;
            $x1=$point->x;$y1=$point->y;$z1=$point->z;
            foreach($forms as [$rotation,$physical,$envelope,$dx,$dy,$dz]){
                $stats->placementsAttempted++;
                $x2=$x1+$dx;$y2=$y1+$dy;$z2=$z1+$dz;
                if($x2>$limitX||$y2>$limitY||$z2>$limitZ)continue;
                $tentative=null;
                if($item->item->nestingHeight!==null){
                    $position=new Point($x1+$clearance,$y1+$clearance,$z1+$clearance);
                    $tentative=new Placement($item,$position,$rotation,$physical,$point,$envelope);
                }
                $blocked=false;
                foreach($index->query($x1,$y1,$z1,$x2,$y2,$z2) as $candidateIndex){
                    [$bx1,$by1,$bz1,$bx2,$by2,$bz2]=$bounds[$candidateIndex];
                    $stats->collisionChecks++;
                    if($x1<$bx2&&$bx1<$x2&&$y1<$by2&&$by1<$y2&&$z1<$bz2&&$bz1<$z2){
                        $placementIndex=$candidateIndex-$placementOffset;
                        if($tentative!==null&&$placementIndex>=0&&Nesting::isValidNesting($placed[$placementIndex],$tentative))continue;
                        $blocked=true;break;
                    }
                }
                if($blocked)continue;
                $ctx=new ConstraintContext($container,$placed,$item,$point,$rotation,$physical,$envelope,$stackSensitive,$routeSensitive);
                foreach($constraints as $constraint){
                    if($constraint instanceof SupportConstraint)$stats->supportChecks++;
                    if(!$constraint->evaluate($ctx)->allowed){$blocked=true;break;}
                }
                if($blocked)continue;
                $position=(($nullsafeVariable1 = $tentative) ? $nullsafeVariable1->position : null)??new Point($x1+$clearance,$y1+$clearance,$z1+$clearance);
                $candidate=new Candidate($point,$position,$rotation,$physical,$envelope,$scorer->score($state,$point,$envelope));
                if($container->voidFillReserveRatio>0&&$item->item->nestingHeight!==null){
                    assert($tentative!==null);
                    $projected=BigInt::add($state->usedVolume,Nesting::usedVolumeDelta($placed,$tentative));
                    if(BigInt::compare($projected,$state->usableVolume())>0)continue;
                }
                $stats->candidatesEvaluated++;
                if($max===1){if($best===null||$candidate->score<$best->score)$best=$candidate;}
                else $out[]=$candidate;
            }
        }
        if($max===1)return $best===null?[]:[$best];
        $out=StableSorter::sortBy($out,static function (Candidate $candidate): array {
            return $candidate->score;
        });
        return $max===null?$out:array_slice($out,0,$max);
    }

    /** @return list<Point> */
    private static function nestingPoints(ContainerState $state,ItemInstance $item):array
    {
        $depth=$item->item->nestingHeight;
        if($depth===null)return [];
        $points=[];
        foreach($state->placements as $placement){
            if($placement->instance->item->id!==$item->item->id)continue;
            $point=new Point($placement->envelopeOrigin->x,$placement->envelopeOrigin->y,$placement->envelopeBox()->z2()-$depth->ticks);
            if($point->z>=0)$points["{$point->x}:{$point->y}:{$point->z}"]=$point;
        }
        $points=array_values($points);
        usort($points,static function (Point $a, Point $b): int {
            return [$a->z,$a->y,$a->x]<=>[$b->z,$b->y,$b->x];
        });
        return $points;
    }

    /**
     * Extra floor-level candidates that seat this item on an axle's own limit.
     *
     * Every other candidate point is flush against a wall or another placed box,
     * which is complete for plain volume packing but not once axle limits are in
     * play: the only feasible spot for an item can be floating in open floor
     * space, away from every wall and every other box, purely to keep that item's
     * own moment off one axle's limit (see `AxleLoad::axleBalancedOrigins`). Floor
     * level only (z=0), since that is the one place `SupportConstraint` grants
     * full support with no lateral contact.
     *
     * @param list<array{0:\Packvium\Domain\Rotation,1:\Packvium\Domain\Dimensions,2:\Packvium\Domain\Dimensions,3:int,4:int,5:int}> $forms
     * @return list<Point>
     */
    private static function axleBalancedPoints(ContainerState $state, ItemInstance $item, array $forms, int $limitX): array
    {
        $container=$state->container;
        $otherUnits=LoadCalculator::units($state->placements);
        $tareTicks=$container->tareWeight->ticks;
        $tareDoubledX=$container->innerDimensions->length->ticks;
        $floorYs=[];
        foreach($state->points as $point)if($point->z===0)$floorYs[$point->y]=true;
        $floorYs=$floorYs===[]?[0]:array_keys($floorYs);
        $points=[];
        foreach($forms as [, , , $dx, , ]){
            foreach(AxleLoad::axleBalancedOrigins($container->axles,$otherUnits,$tareTicks,$tareDoubledX,$item->weight()->ticks,$dx) as $x1){
                if($x1<0||$x1>$limitX-$dx)continue;
                foreach($floorYs as $y)$points[]=new Point($x1,$y,0);
            }
        }
        return $points;
    }
}
