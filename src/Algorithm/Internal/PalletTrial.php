<?php
declare(strict_types=1);
namespace Packvium\Algorithm\Internal;

use Packvium\Config\PackingConfig;
use Packvium\Constraint\{ConstraintContext,SupportConstraint};
use Packvium\Domain\{AxisAlignedBox,Container,Deck,ItemInstance,PalletOverhang,Placement,Point};
use Packvium\Algorithm\{ContainerState,Deadline,SearchStats,SingleContainerSolution,SingleContainerSolver,TimeLimitReached};

/**
 * Trials of a pallet with an overhang limit, loaded only for such a container. A part
 * of `SolverOrchestrator` kept in its own file so PHP compiles it only when a request needs it:
 * without opcache every request compiles what it loads (1.9.0 release frontier). `$call` reaches
 * the orchestrator's own private methods.
 *
 * @internal
 */
final class PalletTrial
{
    /**
     * Pack the deck alone first and use the overhang only when it places more.
     *
     * Every solver starts at its frame's origin, the corner of the overhang zone, so a frame
     * solve overhangs even a load the deck holds, and is billed for it. The deck rung is the
     * container as declared; its trial moves onto the frame by the overhang zone, and a chosen
     * frame trial is seated with the least overhang. Both rungs report coordinates. At most two
     * solves; a deck rung that did not place everything proves nothing about the frame, so a
     * trial that needed both is never exhaustive. The orchestrator sends a frame with fixed items straight
     * to the solver, since they may already overhang.
     *
     * @param list<ItemInstance> $items
     * @return array{0:SingleContainerSolution,1:int}
     */
    public static function palletTrial(\Closure $call,SingleContainerSolver $solver,Container $frame,int $sequence,array $items,PackingConfig $config,SearchStats $stats,Deadline $deadline):array
    {
        /** @var Deck $deck a solving frame always carries its deck */
        $deck=$frame->deck;
        $rungConfig=$config->requirePlacementCoordinates?$config:$call('withCoordinates',$config);
        $onDeck=$solver->packOne(PalletOverhang::deckAlone($frame),$sequence,$items,$rungConfig,$stats,$deadline);
        if($onDeck->state->placementCount()===count($items)||$onDeck->timeLimitReached||$deadline->expired())
            return [self::moved($frame,$onDeck,$deck->x,$deck->y),1];
        try{
            $framed=$solver->packOne($frame,$sequence,$items,$rungConfig,$stats,$deadline);
        }catch(TimeLimitReached){
            return [self::withOutcome(self::moved($frame,$onDeck,$deck->x,$deck->y),true),1];
        }
        if($framed->state->placementCount()>$onDeck->state->placementCount()){
            $seated=self::seated($frame,$framed,$config);
            return [new SingleContainerSolution($seated->state,$seated->unpacked,false,$seated->timeLimitReached,$seated->dominantLattice),2];
        }
        return [self::withOutcome(self::moved($frame,$onDeck,$deck->x,$deck->y),$framed->timeLimitReached),2];
    }

    /** The trial, never exhaustive, with the time limit as the second rung left it. */
    private static function withOutcome(SingleContainerSolution $one,bool $timeLimitReached):SingleContainerSolution
    {
        return new SingleContainerSolution($one->state,$one->unpacked,false,$timeLimitReached,$one->dominantLattice);
    }

    /**
     * A trial's whole load moved by `($dx, $dy)` on the frame, rebuilt so the state's points and
     * index describe the frame. Relative geometry is unchanged, so only a floor placement's
     * support -- its overlap with the deck -- is measured again. O(p) adds.
     */
    private static function moved(Container $frame,SingleContainerSolution $one,int $dx,int $dy):SingleContainerSolution
    {
        $state=new ContainerState($frame,$one->state->sequence);
        foreach($one->state->placements as $placement){
            $origin=self::shifted($placement->envelopeOrigin,$dx,$dy);
            $support=$origin->z===0
                ?PalletOverhang::floorSupportRatio($frame->deck,new AxisAlignedBox($origin,$placement->envelopeDimensions))
                :$placement->supportRatio;
            $state->add(new Placement($placement->instance,self::shifted($placement->position,$dx,$dy),$placement->rotation,$placement->dimensions,
                $origin,$placement->envelopeDimensions,$support,$placement->topLoad,$placement->fixed));
        }
        return new SingleContainerSolution($state,$one->unpacked,$one->exhaustive,$one->timeLimitReached,false);
    }

    /**
     * A frame trial moved to the least overhang (`PalletOverhang::centringOffset`), kept where
     * the solver left it if the move would leave a floor placement short of support. O(p).
     */
    private static function seated(Container $frame,SingleContainerSolution $one,PackingConfig $config):SingleContainerSolution
    {
        /** @var Deck $deck a solving frame always carries its deck */
        $deck=$frame->deck;
        [$dx,$dy]=PalletOverhang::centringOffset($deck,$one->state->placements);
        if($dx===0&&$dy===0)return $one;
        $seated=self::moved($frame,$one,$dx,$dy);
        $support=new SupportConstraint($config->minimumSupportRatio);
        foreach($seated->state->placements as $placement){
            if($placement->envelopeOrigin->z!==0)continue;
            $context=new ConstraintContext($frame,[],$placement->instance,$placement->envelopeOrigin,$placement->rotation,$placement->dimensions,$placement->envelopeDimensions);
            if(!$support->evaluate($context)->allowed)return $one;
        }
        return $seated;
    }

    private static function shifted(Point $point,int $dx,int $dy):Point
    {
        return new Point($point->x+$dx,$point->y+$dy,$point->z);
    }
}
