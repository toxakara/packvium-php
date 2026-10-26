"""Remove a generated directory on a host that keeps writing metadata into it.

On macOS, Finder and Spotlight re-create ``.DS_Store`` inside a directory *while*
``shutil.rmtree`` is emptying it, so the closing ``rmdir`` fails with "Directory not
empty". Linux CI never reproduces it. Retrying removes the stray file if the
writer only wins the race once per attempt.

Failures other than that race are re-raised: a caller that wanted the tree gone must not
be told it is when it is not. The retry only asks ``shutil.rmtree`` to walk the
given tree again; an extra metadata sweep could follow a symlink at its root.
"""

from __future__ import annotations

import shutil
import time
from pathlib import Path


def remove_tree(path: Path, attempts: int = 5, pause_seconds: float = 0.05) -> None:
    for attempt in range(1, attempts + 1):
        try:
            shutil.rmtree(path)
            return
        except FileNotFoundError:
            return
        except OSError:
            if attempt == attempts:
                raise
            time.sleep(pause_seconds)
