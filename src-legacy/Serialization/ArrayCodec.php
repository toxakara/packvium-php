<?php
declare(strict_types=1);
namespace Packvium\Serialization;
use InvalidArgumentException;
use Packvium\Algorithm\EffortBudget;
use Packvium\Config\{PackingConfig,SolverProfile};
use Packvium\Domain\{Axle,AxisAlignedBox,Compression,Container,Dimensions,Item,Obstacle,Point,RateTable,Rotation,ShapeType};
use Packvium\Extension\ExtensionRegistry;
use Packvium\Packer;
use Packvium\Policy\PolicyRuleSet;
use Packvium\Unit\{Length,Weight};
final class ArrayCodec
{
    private static function effortBudget(?array $r):?EffortBudget{if($r===null)return null;return new EffortBudget($r['max_candidates_evaluated']??null,$r['max_placement_attempts']??null,$r['max_search_nodes']??null,$r['max_restarts']??null);}
    /** @return array{0:Axle,1:Axle}|null */
    private static function axles(?array $r,string $unit):?array{if($r===null)return null;[$front,$rear]=$r;return [new Axle(Length::parse($front['position'],$unit),isset($front['max_load'])?Weight::parse($front['max_load']):null),new Axle(Length::parse($rear['position'],$unit),isset($rear['max_load'])?Weight::parse($rear['max_load']):null)];}
    /**
     * Public request fields this engine does not implement yet, by the scope they appear in.
     *
     * Empty today, and that is the point: the lists exist so that adding a public field to
     * the schema before this engine implements it is a *rejection* rather than a silent
     * omission. `pack()` reads the keys it knows and ignores the rest, so without this guard
     * an unimplemented field would produce a confident answer computed as though the caller
     * had never sent it -- indistinguishable, from the outside, from an engine that honoured
     * it. The JavaScript fallback has carried the same table since it was the only engine
     * behind on features; this is its counterpart, so a staged rollout works the same way in
     * every implementation.
     *
     * A name added here must also be recorded in `conformance/public-field-matrix.json` with
     * a `rejected:unsupported_feature` support level for PHP, which is what makes the
     * conformance corpus assert the rejection instead of merely tolerating it.
     *
     * @var array<string,list<string>>
     */
    public const UNSUPPORTED_FIELDS=['request'=>[],'configuration'=>[],
        // `hull_vertices`, `compression_ratio` and `max_compression_pressure_kpa` left this
        // list in , when PHP gained both the solver behaviour and the independent
        // validation the staged rollout requires. Rust and the JavaScript fallback still
        // carry them.
        'item'=>[],'container'=>[]];

    /**
     * `item.shape_type` values this engine does not implement.
     *
     * Empty since : this engine implements every value the schema defines. The guard
     * stays because the next reserved value will need it, and because `rejectUnsupported`
     * takes its lists as parameters precisely so it remains testable when they are empty.
     *
     * Presence is the wrong test for this one field: `rigid_cuboid` is the default and is
     * implemented, so a caller that spells the default out must be served, not refused.
     * What is unimplemented is a *value*, and the refusal has to name it -- an engine that
     * packed a `convex_hull` item as its bounding box would return a plan that looks valid
     * and does not physically fit.
     *
     * @var list<string>
     */
    public const UNSUPPORTED_SHAPE_TYPES=[];

    /**
     * @param array<string,list<string>> $unsupported
     *
     * The lists are a parameter rather than read from the constant directly so the guard
     * itself is testable. With every list empty -- the correct state whenever this engine is
     * caught up -- a test can only prove that nothing is rejected, which is equally true of
     * a guard that does nothing at all.
     */
    public static function rejectUnsupported(array $data,?array $unsupported=null,?array $shapeTypes=null):void
    {
        $unsupported = $unsupported ?? self::UNSUPPORTED_FIELDS;
        $shapeTypes = $shapeTypes ?? self::UNSUPPORTED_SHAPE_TYPES;
        // Keyed by name, not appended per occurrence: fifty containers carrying one
        // unimplemented field are one complaint, not fifty.
        $found=[];
        // Top-level scope: a block such as `policy` describes the whole request rather
        // than one item or container, so no per-entry loop below would ever see it.
        foreach($unsupported['request']??[] as $key){
            if(array_key_exists($key,$data))$found[$key]=true;
        }
        foreach($unsupported['configuration']??[] as $key){
            if(array_key_exists($key,$data['configuration']??[]))$found["configuration.{$key}"]=true;
        }
        foreach(['item'=>'items','container'=>'containers'] as $scope=>$collection){
            foreach($data[$collection]??[] as $entry){
                if(!is_array($entry))continue;
                foreach($unsupported[$scope]??[] as $key){
                    if(array_key_exists($key,$entry))$found["{$scope}.{$key}"]=true;
                }
            }
        }
        foreach($data['items']??[] as $entry){
            if(!is_array($entry))continue;
            $shape=$entry['shape_type']??null;
            if(is_string($shape)&&in_array($shape,$shapeTypes,true))
                $found["item.shape_type={$shape}"]=true;
        }
        if($found===[])return;
        $names=array_keys($found);
        sort($names);
        throw new UnsupportedFeatureException(
            'unsupported_feature: the PHP engine does not yet implement '.implode(', ',$names)
            .'; the request was rejected instead of silently ignoring public fields'
        );
    }

    public static function pack(array $data):array
    {
        self::rejectUnsupported($data);
        $unit=(string)($data['units']['length']??'mm');
        $cfg=$data['configuration']??[];
        $profile=SolverProfile::from($cfg['solver_profile']??'balanced');
        $quality=$profile===SolverProfile::Quality;
        $config=new PackingConfig($profile, (int)($cfg['time_limit_ms']??1000), (int)($cfg['alternatives']??3), (int)($cfg['seed']??42), isset($cfg['max_containers'])?(int)$cfg['max_containers']:null, Length::parse($cfg['clearance']??0,$unit), (float)($cfg['minimum_support_ratio']??0), (int)($cfg['exact_item_limit']??7), (int)($cfg['multi_start_orders']??8), true, (int)($cfg['max_candidates_per_item']??($quality?16:1)), (int)($cfg['max_candidate_points']??4096), array_map('strval',$cfg['solvers']??[]), (string)($cfg['objective']??'default'), self::effortBudget($cfg['effort_budget']??null), isset($cfg['dimensional_weight_divisor'])?(int)$cfg['dimensional_weight_divisor']:null, (string)($cfg['dimensional_weight_length_unit']??'in'), (string)($cfg['dimensional_weight_weight_unit']??'lb'), (bool)($cfg['require_placement_coordinates']??true), 1, (int)($cfg['container_plan_beam_width']??($quality?16:1)), (int)($cfg['container_plan_node_limit']??($quality?100000:1)));
        $items=array_map(function ($r) use ($unit) {
            return self::item($r,$unit);
        },$data['items']);
        $containers=array_map(function ($r) use ($unit) {
            return self::container($r,$unit);
        },$data['containers']);
        $out=$data['output']??[];
        // Rules compile into this engine's own constraint pipeline rather than post-filtering
        // a chosen answer: an illegal candidate is rejected during search, so the packing
        // that wins was never allowed to be illegal in the first place.
        $extensions=new ExtensionRegistry(PolicyRuleSet::fromArray($data['policy']??null)->constraints());
        $result=(new Packer($config,$extensions))->pack($items,$containers)->toArray($out['length_unit']??$unit,$out['weight_unit']??'g');
        $result['catalog_versions_used']=self::catalogVersionsUsed($data['catalog_versions_used']??[]);
        return $result;
    }

    /** @return list<array{catalog_id:string,version:int,effective_at:int,resolved_at:int}>
     * @param mixed $raw */
    private static function catalogVersionsUsed($raw):array
    {
        if(!is_array($raw)){throw new InvalidArgumentException('catalog_versions_used must be an array');}
        $required=['catalog_id','effective_at','resolved_at','version'];
        $seen=[];
        $references=[];
        foreach(array_values($raw) as $index=>$reference){
            if(!is_array($reference)){
                throw new InvalidArgumentException("catalog_versions_used[{$index}] must be an object");
            }
            $keys=array_keys($reference);
            sort($keys);
            if($keys!==$required){
                throw new InvalidArgumentException("catalog_versions_used[{$index}] must contain exactly the canonical fields");
            }
            $catalogId=$reference['catalog_id'];
            if(!is_string($catalogId)||$catalogId===''){
                throw new InvalidArgumentException("catalog_versions_used[{$index}].catalog_id must be non-empty");
            }
            if(isset($seen[$catalogId])){
                throw new InvalidArgumentException("catalog_versions_used contains ambiguous duplicate '{$catalogId}'");
            }
            $seen[$catalogId]=true;
            foreach(['version'=>1,'effective_at'=>0,'resolved_at'=>0] as $field=>$minimum){
                if(!is_int($reference[$field])||$reference[$field]<$minimum){
                    throw new InvalidArgumentException("catalog_versions_used[{$index}].{$field} must be >= {$minimum}");
                }
            }
            $references[]=$reference;
        }
        return $references;
    }
    private static function item(array $r,string $unit):Item{$rot=array_map(function ($v) {
        return Rotation::from($v);
    },$r['allowed_rotations']??array_map(function ($x) {
        return $x;
    },Rotation::all()));return new Item((string)$r['id'],Dimensions::fromArray($r['dimensions'],$unit),Weight::parse($r['weight']??0), (int)($r['quantity']??1),$rot,(bool)($r['keep_upright']??false),(bool)($r['stackable']??true),(bool)($r['must_be_on_floor']??false),isset($r['max_top_load'])?Weight::parse($r['max_top_load']):null,(float)($r['minimum_support_ratio']??0),$r['group']??null,$r['tags']??[],$r['incompatible_tags']??[],(int)($r['priority']??0),$r['metadata']??[],isset($r['max_stacked_items'])?(int)$r['max_stacked_items']:null,$r['eligible_container_tags']??[],$r['ground_contact_rule']??null,isset($r['nesting_height'])?Length::parse($r['nesting_height'],$unit):null,isset($r['stop_index'])?(int)$r['stop_index']:null,isset($r['value'])?(int)$r['value']:null,
        ShapeType::from((string)($r['shape_type']??ShapeType::RIGID_CUBOID->value)),self::hullVertices($r['hull_vertices']??null,$unit),
        isset($r['compression_ratio'])?Compression::ratioToPpm((float)$r['compression_ratio']):null,
        isset($r['max_compression_pressure_kpa'])?(int)$r['max_compression_pressure_kpa']:null);}

    /**
     * Parse `hull_vertices` into the integer tick frame before any geometry runs.
     *
     * Coordinates go through `Length`, which refuses a negative value, so a hull crossing the
     * wire is authored as non-negative offsets from the corner of its own bounding box. A
     * library caller may still centre a hull wherever it likes -- `HullShape::rotate`
     * normalises either way -- but the wire keeps one convention so four engines cannot
     * disagree about where an item's frame starts.
     *
     * @return list<array{int,int,int}>|null
     */
    private static function hullVertices(?array $raw,string $unit):?array
    {
        if($raw===null)return null;
        $out=[];
        foreach($raw as $vertex)$out[]=[
            Length::parse($vertex['x'],$unit)->ticks,
            Length::parse($vertex['y'],$unit)->ticks,
            Length::parse($vertex['z'],$unit)->ticks,
        ];
        return $out;
    }
    private static function box(array $r,string $unit):AxisAlignedBox{$origin=$r['origin']??[];return new AxisAlignedBox(new Point(Length::parse($origin['x']??0,$unit)->ticks,Length::parse($origin['y']??0,$unit)->ticks,Length::parse($origin['z']??0,$unit)->ticks),Dimensions::fromArray($r['dimensions'],$unit));}
    private static function container(array $r,string $unit):Container{$obs=[];foreach($r['obstacles']??[] as $o){$additional=array_map(function ($b) use ($unit) {
        return self::box($b,$unit);
    },$o['additional_boxes']??[]);$obs[]=new Obstacle((string)$o['id'],self::box($o,$unit),$additional);}return new Container((string)$r['id'],Dimensions::fromArray($r['inner_dimensions'],$unit),isset($r['outer_dimensions'])?Dimensions::fromArray($r['outer_dimensions'],$unit):null,Weight::parse($r['tare_weight']??0),isset($r['max_payload'])?Weight::parse($r['max_payload']):null,(int)($r['cost_minor']??0),isset($r['quantity'])?(int)$r['quantity']:null,$obs,$r['tags']??[],isset($r['max_items'])?(int)$r['max_items']:null,$r['metadata']??[],(float)($r['void_fill_reserve_ratio']??0),array_map(static function ($v) {
        return (int)$v;
    },$r['tag_limits']??[]),isset($r['max_stack_density'])?Weight::parse($r['max_stack_density']):null,self::axles($r['axles']??null,$unit),self::rateTable($r['rate_table']??null));}
    private static function rateTable(?array $r):?RateTable{if($r===null)return null;return new RateTable(array_map('intval',$r['weight_brackets_g']),array_map('intval',$r['prices_minor']),(int)($r['minimum_charge_minor']??0),(int)($r['fuel_surcharge_permille']??0));}
}
