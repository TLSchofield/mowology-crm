#!/usr/bin/env python3
"""bar-smoothness.py — does the tab bar's bead keep moving while the app is blocked?

Record the simulator running the proof demo, then measure the video:

    xcrun simctl launch booted ca.mowology.crm -mwBarDemo stall
    xcrun simctl io booted recordVideo --codec h264 bar.mov     (stop with ctrl-C after ~12 s)
    python3 ios/tools/bar-smoothness.py bar.mov [bead colour as hex, default 1A5A39: the demo's green]

The demo sends the bead across the bar eight times and blocks the app for 150 ms just after each slide starts.
For every slide this prints how long the bead stood still in mid-slide. A bead driven by the app freezes for the
whole block; a bead driven by Core Animation does not.
"""
import subprocess
import sys
import tempfile
from pathlib import Path

import numpy as np
from PIL import Image

FPS = 60
# The bead's raised cap on an iPhone 15 (852 pt tall): an 18 pt strip from the bead's top to just above the bar's
# edge, where nothing else has the bead's colour. Only this strip is taken out of the video.
STRIP = "crop=iw:ih*18/852:0:ih*738/852"


def bead_x(frame: Path, scale: float, colour):
    """The bead's centre along the bar in points, or None if it is not in the frame."""
    band = np.asarray(Image.open(frame).convert("RGB"), dtype=np.int16)
    match = (np.abs(band - colour).sum(axis=2) < 40).sum(axis=0)
    if match.sum() < 30:
        return None
    return float((match * np.arange(match.size)).sum() / match.sum() / scale)


def main():
    if len(sys.argv) not in (2, 3):
        sys.exit(__doc__)
    colour = np.array(list(bytes.fromhex(sys.argv[2] if len(sys.argv) == 3 else "1A5A39")))
    with tempfile.TemporaryDirectory() as tmp:
        subprocess.run(["ffmpeg", "-loglevel", "error", "-i", sys.argv[1], "-vf", STRIP, "-vsync", "cfr", "-r", str(FPS),
                        f"{tmp}/%05d.png"], check=True)
        frames = sorted(Path(tmp).glob("*.png"))
        if not frames:
            sys.exit("no frames in the video")
        scale = Image.open(frames[0]).width / 393.0   # iPhone 15 is 393 pt wide
        xs = [bead_x(f, scale, colour) for f in frames]

    # A slide: frames from the first move away from rest to the last move before the next rest of 20+ frames.
    moves = [i for i in range(1, len(xs)) if xs[i] is not None and xs[i - 1] is not None and abs(xs[i] - xs[i - 1]) > 0.4]
    slides, start = [], None
    for a, b in zip(moves, moves[1:] + [10 ** 9]):
        start = a if start is None else start
        if b - a > 20:
            slides.append((start, a))
            start = None
    if not slides:
        sys.exit("the bead never moved: is the demo running with -mwBarDemo stall?")

    worst = 0
    for n, (first, last) in enumerate(slides, 1):
        # Only the approach counts: within 5 pt of where it lands the bead is slow by design (the spring's turn and tail).
        still = longest = 0
        for i in range(first, last + 1):
            if abs(xs[i] - xs[last]) < 5:
                break
            still = still + 1 if abs(xs[i] - xs[i - 1]) <= 0.4 else 0
            longest = max(longest, still)
        worst = max(worst, longest)
        print(f"slide {n}: {abs(xs[last] - xs[first - 1]):5.0f} pt in {(last - first + 1) * 1000 // FPS:4d} ms, "
              f"stood still on the way for {longest * 1000 // FPS:3d} ms")
    print(f"longest freeze: {worst * 1000 // FPS} ms ({'smooth' if worst <= 2 else 'STALLED'})")
    sys.exit(0 if worst <= 2 else 1)


if __name__ == "__main__":
    main()
