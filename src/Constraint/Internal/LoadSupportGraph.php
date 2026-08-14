<?php
declare(strict_types=1);
namespace Packvium\Constraint\Internal;
use Packvium\Constraint\{ContactEdge,ContactGraph,LoadUnit};
use Packvium\Domain\{AxisAlignedBox,ItemInstance,Placement};

/**
 * Direct load support: ordinary face contact plus exact adjacent nesting contact.
 *
 * Nesting-capable units are sorted by declared item type, exact XY footprint and
 * height, so only neighbours in one possible nested column are compared. Sorting is
 * O(n log n), and scanning/rebuilding the existing contact edges is O(E), not an
 * all-pairs scan. Once an adjacent nesting predecessor exists, all same-column face
 * edges are replaced by that predecessor alone. Final edge and child lists retain
 * ascending unit-index order, preserving ContactGraph's remainder/traversal contract.
 */
final class LoadSupportGraph
{
    /** @var list<list<ContactEdge>> */
    private array $supporters;
    /** @var list<list<int>> */
    private array $children;

    /** @param list<LoadUnit> $units */
    public function __construct(array $units)
    {
        /** @var list<array{item:string,depth:int,x1:int,y1:int,x2:int,y2:int,z1:int,z2:int,index:int}> $nesting */
        $nesting=[];
        foreach($units as $index=>$unit){
            if($unit->nestingItemId===null||$unit->nestingHeightTicks===null)continue;
            $nesting[]=[
                'item'=>$unit->nestingItemId,
                'depth'=>$unit->nestingHeightTicks,
                'x1'=>$unit->box->origin->x,'y1'=>$unit->box->origin->y,
                'x2'=>$unit->box->x2(),'y2'=>$unit->box->y2(),
                'z1'=>$unit->box->origin->z,'z2'=>$unit->box->z2(),'index'=>$index,
            ];
        }
        $face=new ContactGraph(array_map(static fn(LoadUnit $unit):AxisAlignedBox=>$unit->box,$units));
        $supporters=$children=[];
        foreach($units as $index=>$_){
            $supporters[$index]=$face->supporters($index);
            $children[$index]=$face->children($index);
        }
        if($nesting===[]){
            $this->supporters=$supporters;
            $this->children=$children;
            return;
        }
        usort($nesting,static function(array $left,array $right):int{
            $item=strcmp($left['item'],$right['item']);
            return $item!==0?$item:[
                $left['depth'],$left['x1'],$left['y1'],$left['x2'],$left['y2'],$left['z1'],$left['z2'],$left['index'],
            ]<=>[
                $right['depth'],$right['x1'],$right['y1'],$right['x2'],$right['y2'],$right['z1'],$right['z2'],$right['index'],
            ];
        });
        for($position=1,$count=count($nesting);$position<$count;$position++){
            $lower=$nesting[$position-1];$upper=$nesting[$position];
            if([
                $lower['item'],$lower['depth'],$lower['x1'],$lower['y1'],$lower['x2'],$lower['y2'],
            ]!==[
                $upper['item'],$upper['depth'],$upper['x1'],$upper['y1'],$upper['x2'],$upper['y2'],
            ]||$lower['z1']>=$upper['z1'])continue;
            $lowerIndex=$lower['index'];$upperIndex=$upper['index'];
            if($units[$lowerIndex]->box->z2()-$units[$upperIndex]->box->origin->z!==$units[$lowerIndex]->nestingHeightTicks)continue;
            $retained=[];
            foreach($supporters[$upperIndex] as $edge){
                $supporter=$units[$edge->index];
                $sameColumn=$supporter->nestingItemId===$upper['item']
                    &&$supporter->nestingHeightTicks===$upper['depth']&&[
                    $supporter->box->origin->x,$supporter->box->origin->y,
                    $supporter->box->x2(),$supporter->box->y2(),
                ]===[
                    $upper['x1'],$upper['y1'],$upper['x2'],$upper['y2'],
                ];
                if(!$sameColumn)$retained[]=$edge;
            }
            $area=$units[$lowerIndex]->box->overlapAreaXY($units[$upperIndex]->box);
            $insertAt=0;$retainedCount=count($retained);
            while($insertAt<$retainedCount&&$retained[$insertAt]->index<$lowerIndex)$insertAt++;
            array_splice($retained,$insertAt,0,[new ContactEdge($lowerIndex,$area)]);
            $supporters[$upperIndex]=$retained;
        }
        $children=array_fill(0,count($units),[]);
        foreach($supporters as $childIndex=>$edges)
            foreach($edges as $edge)$children[$edge->index][]=$childIndex;
        $this->supporters=$supporters;
        $this->children=$children;
    }

    /** @return list<ContactEdge> */
    public function supporters(int $index):array{return $this->supporters[$index];}
    /** @return list<int> */
    public function children(int $index):array{return $this->children[$index];}

    /**
     * A direct-support edge may never originate at a non-stackable item.
     *
     * @param list<Placement> $placements
     * @return array{0:string,1:string}|null
     */
    public function nonStackableFailure(array $placements,ItemInstance $item,int $candidateIndex):?array
    {
        foreach($this->supporters($candidateIndex) as $edge){
            $supporter=$placements[$edge->index];
            if(!$supporter->instance->item->stackable)
                return ['non_stackable',$supporter->instance->item->id];
        }
        if(!$item->item->stackable&&$this->children($candidateIndex)!==[])
            return ['non_stackable',$item->item->id];
        return null;
    }

    private static function sameNestingColumn(Placement $placement,ItemInstance $item,AxisAlignedBox $box):bool
    {
        $other=$placement->envelopeBox();
        return $placement->instance->item->id===$item->item->id
            &&$placement->instance->item->nestingHeight!==null
            &&$item->item->nestingHeight!==null
            &&$placement->instance->item->nestingHeight->ticks===$item->item->nestingHeight->ticks
            &&[$other->origin->x,$other->origin->y,$other->x2(),$other->y2()]
                ===[$box->origin->x,$box->origin->y,$box->x2(),$box->y2()];
    }

    /**
     * Direct support for one active candidate: O(m) time and O(m) output space.
     *
     * Ordinary face semantics are retained exactly. With an exact adjacent nesting
     * predecessor, shadowed same-column face surfaces are removed and that predecessor
     * becomes one full-area supporter. Every returned list stays in placement-index
     * order, so remainder and traversal decisions remain canonical.
     *
     * @param list<Placement> $placements
     */
    public static function candidateView(array $placements,ItemInstance $item,AxisAlignedBox $box):DirectSupportView
    {
        $surfaceEntries=[];$supporterEntries=[];$nearestLower=null;
        $nesting=$item->item->nestingHeight;
        foreach($placements as $index=>$placement){
            $other=$placement->envelopeBox();
            if($other->z2()===$box->origin->z){
                $surfaceEntries[]=['index'=>$index,'box'=>$other];
                $area=$other->overlapAreaXY($box);
                if($area>0)$supporterEntries[]=['index'=>$index,'box'=>$other,'area'=>$area];
            }
            $otherNesting=$placement->instance->item->nestingHeight;
            if($nesting!==null&&$otherNesting!==null
                &&self::sameNestingColumn($placement,$item,$box)
                &&$other->origin->z<$box->origin->z
                &&($nearestLower===null||$other->origin->z>$nearestLower['box']->origin->z))
                $nearestLower=['index'=>$index,'box'=>$other];
        }
        $predecessor=$nearestLower!==null&&$nesting!==null
            &&$nearestLower['box']->z2()-$box->origin->z===$nesting->ticks
            ?$nearestLower:null;
        if($predecessor!==null){
            $surfaceEntries=array_values(array_filter(
                $surfaceEntries,
                static fn(array $entry):bool=>!self::sameNestingColumn($placements[$entry['index']],$item,$box),
            ));
            $supporterEntries=array_values(array_filter(
                $supporterEntries,
                static fn(array $entry):bool=>!self::sameNestingColumn($placements[$entry['index']],$item,$box),
            ));
            $surfaceAt=0;
            while($surfaceAt<count($surfaceEntries)&&$surfaceEntries[$surfaceAt]['index']<$predecessor['index'])$surfaceAt++;
            array_splice($surfaceEntries,$surfaceAt,0,[$predecessor]);
            $supporterAt=0;
            while($supporterAt<count($supporterEntries)&&$supporterEntries[$supporterAt]['index']<$predecessor['index'])$supporterAt++;
            array_splice($supporterEntries,$supporterAt,0,[[
                'index'=>$predecessor['index'],'box'=>$predecessor['box'],
                'area'=>$predecessor['box']->overlapAreaXY($box),
            ]]);
        }
        $indexes=[];$boxes=[];$areas=[];$totalArea=0;
        foreach($supporterEntries as $entry){
            $indexes[]=$entry['index'];$boxes[]=$entry['box'];$areas[]=$entry['area'];$totalArea+=$entry['area'];
        }
        return new DirectSupportView(
            array_map(static fn(array $entry):AxisAlignedBox=>$entry['box'],$surfaceEntries),
            $indexes,$boxes,$areas,$totalArea,
        );
    }
}
