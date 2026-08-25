<?php
declare(strict_types=1);
namespace Packvium\Serialization;
use InvalidArgumentException;

/**
 * A public request field this engine does not implement yet.
 *
 * Refusing is the whole point. Ignoring an unimplemented field returns a confident
 * answer computed as though the caller had never sent it, and from the outside that is
 * indistinguishable from an engine that honoured the field -- the failure mode the
 * public-field evidence audit exists to catch. The message opens with the
 * `unsupported_feature` code the conformance corpus matches on, so a staged rollout can
 * assert the rejection rather than merely tolerate it.
 */
final class UnsupportedFeatureException extends InvalidArgumentException{}
