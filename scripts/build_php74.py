#!/usr/bin/env python3
"""Build the generated, non-canonical distribution tree for PHP below 8.2.

The floor is whatever `tools/php-compat/rector.php` targets and the generated
`composer.json` declares -- 7.3 as of 0.1.3. The `php74` in this file's own name is
historical and means nothing; the tree is never shipped under it.

It runs in two layouts. In the development workspace the canonical source is the
`packvium-php/` directory beside `scripts/`. In a checkout of the published packvium-php
repository, which carries this script, `tools/php-compat/` and `scripts/fs_cleanup.py`, the
canonical source is the checkout itself. Either way the tree is written to `--output`,
relative to the directory holding `scripts/`:

    composer install --working-dir=tools/php-compat
    python3 scripts/build_php74.py                      # writes build/php-legacy/
    python3 scripts/build_php74.py --verify             # checks it against its receipt
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import shutil
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from fs_cleanup import remove_tree

ROOT = Path(__file__).resolve().parents[1]
WORKSPACE_SOURCE = ROOT / "packvium-php"
SOURCE = WORKSPACE_SOURCE if WORKSPACE_SOURCE.is_dir() else ROOT
DEFAULT_OUTPUT = Path("build") / "php-legacy"
OUTPUT = ROOT / DEFAULT_OUTPUT
RECTOR = ROOT / "tools" / "php-compat" / "vendor" / "bin" / "rector"
RECTOR_CONFIG = ROOT / "tools" / "php-compat" / "rector.php"
RECEIPT = OUTPUT / "BUILD-RECEIPT.json"
FORMAT = "packvium-php74-build/v1"
SOURCE_INPUTS = (
    "LICENSE",
    "README.md",
    "autoload.php",
    "bin",
    "composer.json",
    "examples",
    "src",
)
TOOLCHAIN_INPUTS = (
    Path("scripts/build_php74.py"),
    Path("tools/php-compat/composer.json"),
    Path("tools/php-compat/composer.lock"),
    Path("tools/php-compat/rector.php"),
)


def _use_output(output: Path) -> None:
    """Point the build and its receipt at ``output``, relative to the directory holding scripts/."""
    global OUTPUT, RECEIPT
    OUTPUT = ROOT / output
    RECEIPT = OUTPUT / "BUILD-RECEIPT.json"


def _copy_source() -> None:
    """Copy exactly the inputs the receipt binds, so both layouts generate the same tree.

    A checkout of the published repository also holds this toolchain, the generated
    ``src-legacy/``, its docs and its CI; none of them is source, and copying the whole
    directory would put them in the output.
    """
    if OUTPUT.exists():
        remove_tree(OUTPUT)
    OUTPUT.mkdir(parents=True)
    ignore = shutil.ignore_patterns(".DS_Store", ".gitignore", ".phpunit.cache", "vendor")
    for relative in SOURCE_INPUTS:
        origin = SOURCE / relative
        if origin.is_dir():
            shutil.copytree(origin, OUTPUT / relative, ignore=ignore)
        else:
            shutil.copy2(origin, OUTPUT / relative)


def _rewrite_metadata() -> None:
    composer_path = OUTPUT / "composer.json"
    composer = json.loads(composer_path.read_text())
    composer["require"]["php"] = ">=7.3"
    composer.pop("autoload-dev", None)
    composer.get("scripts", {}).pop("test", None)
    composer["extra"] = {
        **composer.get("extra", {}),
        "packvium": {
            "canonical_source": "PHP 8.2+",
            "compatibility_artifact": "PHP 7.3",
        },
    }
    composer_path.write_text(json.dumps(composer, indent=2) + "\n")
    (OUTPUT / "LEGACY-COMPATIBILITY.md").write_text(
        "# Legacy PHP compatibility artifact\n\n"
        "This tree is generated from the canonical Packvium PHP source with the "
        "pinned AST downgrade toolchain. It exposes the same namespaces and public "
        "features, but every PHP version it exists for is end-of-life and receives no upstream "
        "security updates. Prefer a currently supported PHP whenever possible.\n"
    )


def _downgrade() -> None:
    if not RECTOR.is_file():
        raise SystemExit(
            "missing tools/php-compat/vendor/bin/rector; "
            "run `composer install --working-dir=tools/php-compat`"
        )
    env = os.environ.copy()
    env["PACKVIUM_PHP74_ROOT"] = str(OUTPUT)
    command = [
        str(RECTOR),
        "process",
        "--config",
        str(RECTOR_CONFIG),
        "--no-progress-bar",
        "--no-diffs",
    ]
    first = subprocess.run(command, cwd=ROOT, env=env, check=False)
    if first.returncode == 0:
        return

    # Rector writes every successfully transformed file before reporting a
    # file-specific reflection failure. A second pass over that simpler intermediate
    # tree is deterministic and is required for the 0.1.2 examples; never accept the
    # partial first pass unless the bounded retry itself completes successfully.
    print("legacy PHP downgrade: retrying once after Rector's partial-write failure")
    second = subprocess.run(command, cwd=ROOT, env=env, check=False)
    if second.returncode != 0:
        raise subprocess.CalledProcessError(second.returncode, command)


def _patch_downgraded_enums() -> None:
    """Complete Rector's scalar-backed-enum lowering for typed public properties.

    Rector correctly replaces enum cases with string constants, but intentionally
    leaves a few value-oriented usages for project-specific handling.  Packvium's
    enums are closed scalar vocabularies, so the downgraded representation is a final
    constants class with deterministic ``from``/``cases`` helpers.
    """

    enum_types = ("SolverProfile", "PackingStatus", "Rounding", "Rotation", "ShapeType")
    type_pattern = re.compile(r"\b(?:" + "|".join(enum_types) + r")\s+\$")
    # `->value` must strip only where it accesses an actual (now string-backed)
    # enum member -- never a same-named but unrelated property such as the
    # `maximum_value` objective's `Item::$value`, which a blind `str.replace("->value", "")` would corrupt into
    # `$this=$value` by deleting the `->value` out of `$this->value=$value`.
    # Enum-holding names are collected from two shapes, across every file first
    # (a name's *type* is declared in whichever file defines that property/param,
    # not necessarily the file that later chains through it):
    #   - a typed property or parameter: `SolverProfile $profile`, `Rotation $rotation`
    #   - the `Type::all()`/`Type::cases()` iteration idiom: `fn($x) => $x->value`,
    #     whose untyped closure parameter is never caught by the first shape
    name_pattern = re.compile(r"\b(?:" + "|".join(enum_types) + r")\s+\$(\w+)")
    arrow_pattern = re.compile(r"fn\s*\(\s*\$(\w+)\s*\)\s*=>\s*\$\1->value\b")
    sources = {path: path.read_text() for path in (OUTPUT / "src").rglob("*.php")}
    enum_names: set[str] = set()
    for source in sources.values():
        enum_names.update(name_pattern.findall(source))
        enum_names.update(arrow_pattern.findall(source))
    access_pattern = re.compile(
        r"(\$(?:" + "|".join(re.escape(name) for name in enum_names) + r")"
        r"|->(?:" + "|".join(re.escape(name) for name in enum_names) + r"))->value\b"
    ) if enum_names else None
    # A third shape, and the one that shipped broken: the case is fetched from the class
    # rather than held in a name -- `ShapeType::RIGID_CUBOID->value`. The two patterns above
    # only see `$name->value` and `->prop->value`, so this survived the lowering as a
    # property fetch on a string constant: a PHP 8.1 parse error, on the one runtime range
    # this tree exists to serve. It is scoped to the enum class names above so a genuine
    # `Something::CONST->value` on a real object is left alone.
    case_access_pattern = re.compile(
        r"\b((?:" + "|".join(enum_types) + r")::[A-Za-z_]\w*)->value\b"
    )
    for path, source in sources.items():
        source = type_pattern.sub("string $", source)
        if access_pattern is not None:
            source = access_pattern.sub(r"\1", source)
        source = case_access_pattern.sub(r"\1", source)
        path.write_text(source)

    templates = {
        "Config/SolverProfile.php": (
            "<?php\ndeclare(strict_types=1);\nnamespace Packvium\\Config;\n"
            "use InvalidArgumentException;\n"
            "final class SolverProfile\n{\n"
            "    public const Fast='fast', Balanced='balanced', Quality='quality', ExactSmall='exact_small';\n"
            "    public static function cases():array{return [self::Fast,self::Balanced,self::Quality,self::ExactSmall];}\n"
            "    public static function from(string $value):string{if(!in_array($value,self::cases(),true))"
            "throw new InvalidArgumentException('Unknown solver profile '.$value);return $value;}\n"
            "}\n"
        ),
        "Result/PackingStatus.php": (
            "<?php\ndeclare(strict_types=1);\nnamespace Packvium\\Result;\n"
            "use InvalidArgumentException;\n"
            "final class PackingStatus\n{\n"
            "    public const Optimal='optimal', Feasible='feasible', BestFound='best_found', "
            "TimeLimit='time_limit', Infeasible='infeasible', InvalidResult='invalid_result';\n"
            "    public static function cases():array{return [self::Optimal,self::Feasible,self::BestFound,"
            "self::TimeLimit,self::Infeasible,self::InvalidResult];}\n"
            "    public static function from(string $value):string{if(!in_array($value,self::cases(),true))"
            "throw new InvalidArgumentException('Unknown packing status '.$value);return $value;}\n"
            "}\n"
        ),
        "Unit/Rounding.php": (
            "<?php\ndeclare(strict_types=1);\nnamespace Packvium\\Unit;\n"
            "use InvalidArgumentException;\n"
            "final class Rounding\n{\n"
            "    public const Floor='floor', Ceil='ceil', Nearest='nearest';\n"
            "    public static function cases():array{return [self::Floor,self::Ceil,self::Nearest];}\n"
            "    public static function from(string $value):string{if(!in_array($value,self::cases(),true))"
            "throw new InvalidArgumentException('Unknown rounding mode '.$value);return $value;}\n"
            "}\n"
        ),
        "Domain/Rotation.php": (
            "<?php\ndeclare(strict_types=1);\nnamespace Packvium\\Domain;\n"
            "use InvalidArgumentException;\n"
            "final class Rotation\n{\n"
            "    public const LWH='LWH', LHW='LHW', WLH='WLH', WHL='WHL', HLW='HLW', HWL='HWL';\n"
            "    public static function cases():array{return [self::LWH,self::LHW,self::WLH,self::WHL,self::HLW,self::HWL];}\n"
            "    public static function all():array{return self::cases();}\n"
            "    public static function upright():array{return [self::LWH,self::WLH];}\n"
            "    public static function from(string $value):string{if(!in_array($value,self::cases(),true))"
            "throw new InvalidArgumentException('Unknown rotation '.$value);return $value;}\n"
            "}\n"
        ),
        "Domain/ShapeType.php": (
            "<?php\ndeclare(strict_types=1);\nnamespace Packvium\\Domain;\n"
            "use InvalidArgumentException;\n"
            "final class ShapeType\n{\n"
            "    public const RIGID_CUBOID='rigid_cuboid', CONVEX_HULL='convex_hull', COMPRESSIBLE='compressible';\n"
            "    public static function cases():array{return [self::RIGID_CUBOID,self::CONVEX_HULL,self::COMPRESSIBLE];}\n"
            "    public static function from(string $value):string{if(!in_array($value,self::cases(),true))"
            "throw new InvalidArgumentException('Unknown shape type '.$value);return $value;}\n"
            "}\n"
        ),
    }
    for relative, content in templates.items():
        (OUTPUT / "src" / relative).write_text(content)
    _assert_enum_lowering_complete(enum_types)


#: A lowered enum is a constants class. Two usages therefore stop working and neither is
#: visible to `php -l` on a modern host, because both are valid PHP 8.1: fetching `->value`
#: from a case, and calling `from`/`tryFrom`/`cases` on the class. `ShapeType` was added later,
#: never joined `enum_types`, and shipped inside `packvium/packvium@1.0.0` as
#: `src-legacy/Serialization/ArrayCodec.php` -- a parse error on every runtime the package
#: advertises. A hand-maintained list is what failed, so this asserts the *outcome*.
ENUM_LEAK_PATTERNS = (
    ("a `->value` fetch on a lowered case", r"::[A-Za-z_]\w*->value\b"),
    ("a call to a method the lowering removes", r"::(?:from|tryFrom|cases)\("),
)


def _assert_enum_lowering_complete(enum_types: tuple[str, ...]) -> None:
    """Fail the build if any lowered enum is still used as an enum.

    Scoped to the classes that are `enum` in the canonical source, so an unrelated class
    with a `from()` factory is not implicated. A class listed in ``enum_types`` gets its
    helpers written back by a template above and is exempt from the second pattern only --
    never from the first, which is a parse error regardless.
    """
    declared = {
        path.stem for path in (SOURCE / "src").rglob("*.php")
        if re.search(r"(?m)^enum\s+\w", path.read_text())
    }
    if not declared:
        raise SystemExit("build_php74: no enums found in the canonical source to lower")
    offenders: list[str] = []
    for path in sorted((OUTPUT / "src").rglob("*.php")):
        source = path.read_text()
        for label, suffix in ENUM_LEAK_PATTERNS:
            exempt = set(enum_types) if "method" in label else set()
            for name in sorted(declared - exempt):
                if re.search(r"\b" + re.escape(name) + suffix, source):
                    offenders.append(f"{path.relative_to(OUTPUT)}: {name} -- {label}")
    if offenders:
        shown = "\n  ".join(sorted(set(offenders))[:10])
        raise SystemExit(
            f"build_php74: the enum lowering is incomplete in {len(set(offenders))} "
            f"place(s); add the class to `enum_types` and give it a template:\n  {shown}"
        )


def _digest(paths: list[Path], base: Path) -> tuple[str, int]:
    digest = hashlib.sha256()
    files = sorted(path for path in paths if path.is_file())
    for path in files:
        digest.update(path.relative_to(base).as_posix().encode())
        digest.update(b"\0")
        digest.update(hashlib.sha256(path.read_bytes()).digest())
        digest.update(b"\0")
    return digest.hexdigest(), len(files)


def _source_digest() -> tuple[str, int]:
    paths: list[Path] = []
    for relative in SOURCE_INPUTS:
        candidate = SOURCE / relative
        paths.extend(candidate.rglob("*") if candidate.is_dir() else [candidate])
    return _digest(paths, SOURCE)


def _toolchain_digest() -> tuple[str, int]:
    return _digest([ROOT / path for path in TOOLCHAIN_INPUTS], ROOT)


def _artifact_digest() -> tuple[str, int]:
    return _digest(
        [
            path
            for path in OUTPUT.rglob("*")
            # Finder writes .DS_Store into the fresh tree between the build and its verify.
            if path.name not in {"BUILD-RECEIPT.json", ".DS_Store"}
        ],
        OUTPUT,
    )


def _lint_on_host_php() -> list[str]:
    """Parse every generated file with the host interpreter, not only on the floor.

    The tree serves 7.3 through 8.1 -- `autoload.php` hands it every runtime below 8.2 --
    so "valid on the floor" is not the requirement; "valid on the whole range" is. Exactly
    one construct separates the two, and Rector emits it: an unparenthesized nested ternary,
    deprecated on the old runtimes and a fatal parse error from 8.0. `DowngradeArraySpreadRector`
    produces one for every `[...$x]` whose operand it cannot prove is an array, so three
    solvers shipped a tree that the 7.4 gate passed and PHP 8.0 could not even parse.
    Write `array_merge($a, $b)` instead of `[...$a, ...$b]` in downgraded source.
    """
    files = sorted(
        path for relative in ("src", "bin", "examples")
        for path in (OUTPUT / relative).rglob("*")
        if path.is_file() and path.suffix in {".php", ""}
    ) + [OUTPUT / "autoload.php"]
    offenders = []
    for path in files:
        result = subprocess.run(
            ["php", "-l", str(path)], capture_output=True, text=True, check=False
        )
        if result.returncode != 0:
            detail = (result.stdout + result.stderr).strip().splitlines()
            offenders.append(f"{path.relative_to(OUTPUT)}: {detail[0] if detail else 'parse error'}")
    return offenders


def build() -> int:
    _copy_source()
    _rewrite_metadata()
    # Lower the enums first, while the canonical type syntax is still there to read. The
    # post-pass recognises an enum-typed name from a typed property or parameter and from
    # the `fn($x) => $x->value` idiom -- both of which the downgrade rewrites away below
    # 7.4, so running it afterwards silently missed three names and left `->value` on what
    # was by then a plain string. PHP 7 evaluates that to null instead of raising.
    _patch_downgraded_enums()
    _downgrade()
    source_digest, source_file_count = _source_digest()
    toolchain_digest, toolchain_file_count = _toolchain_digest()
    artifact_digest, artifact_file_count = _artifact_digest()
    receipt = {
        "format": FORMAT,
        "canonical_source_digest": source_digest,
        "canonical_source_file_count": source_file_count,
        "toolchain_digest": toolchain_digest,
        "toolchain_file_count": toolchain_file_count,
        "artifact_digest": artifact_digest,
        "artifact_file_count": artifact_file_count,
        "minimum_php": "7.3",
        "output": str(OUTPUT.relative_to(ROOT)),
    }
    RECEIPT.write_text(json.dumps(receipt, indent=2) + "\n")
    print(f"generated {OUTPUT.relative_to(ROOT)} from canonical PHP source")
    return 0


def verify() -> int:
    try:
        receipt = json.loads(RECEIPT.read_text())
        composer = json.loads((OUTPUT / "composer.json").read_text())
    except (OSError, json.JSONDecodeError) as error:
        print(f"legacy PHP artifact: FAIL — unreadable build output: {error}")
        return 1

    source_digest, source_file_count = _source_digest()
    toolchain_digest, toolchain_file_count = _toolchain_digest()
    artifact_digest, artifact_file_count = _artifact_digest()
    expected = {
        "format": FORMAT,
        "canonical_source_digest": source_digest,
        "canonical_source_file_count": source_file_count,
        "toolchain_digest": toolchain_digest,
        "toolchain_file_count": toolchain_file_count,
        "artifact_digest": artifact_digest,
        "artifact_file_count": artifact_file_count,
        "minimum_php": "7.3",
        "output": str(OUTPUT.relative_to(ROOT)),
    }
    problems = [
        f"{field} is stale ({receipt.get(field)!r} != {value!r})"
        for field, value in expected.items()
        if receipt.get(field) != value
    ]
    if composer.get("require", {}).get("php") != ">=7.3":
        problems.append("generated Composer metadata does not require php >=7.3")
    forbidden = [
        relative
        for relative in ("build", "composer.lock", "tests", "vendor")
        if (OUTPUT / relative).exists()
    ]
    if forbidden:
        problems.append(f"transient paths leaked into artifact: {', '.join(forbidden)}")
    problems.extend(_lint_on_host_php())
    if problems:
        for problem in problems:
            print(f"legacy PHP artifact: FAIL — {problem}")
        return 1
    host = subprocess.run(
        ["php", "-r", "echo PHP_VERSION;"], capture_output=True, text=True, check=True
    ).stdout.strip()
    print(
        "legacy PHP artifact: PASS "
        f"({artifact_file_count} files, sha256 {artifact_digest}; parses on PHP {host})"
    )
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--verify",
        action="store_true",
        help="verify the generated tree and its source/toolchain-bound receipt",
    )
    parser.add_argument(
        "--output",
        type=Path,
        default=DEFAULT_OUTPUT,
        help=f"where the tree is written, relative to the directory holding scripts/ (default {DEFAULT_OUTPUT})",
    )
    arguments = parser.parse_args()
    _use_output(arguments.output)
    return verify() if arguments.verify else build()


if __name__ == "__main__":
    raise SystemExit(main())
