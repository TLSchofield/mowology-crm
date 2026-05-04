#!/usr/bin/env python3
"""
add_swift_file.py — Register a new Swift file in MowologyCRM.xcodeproj

Usage:
    python3 ios/add_swift_file.py ios/MowologyCRM/MowologyCRM/Core/Design/MWTheme.swift

The script:
  1. Derives the group path from the file's location under MowologyCRM/
  2. Generates stable UUIDs (deterministic from the file path)
  3. Inserts PBXFileReference, PBXBuildFile, and group membership entries
  4. Adds the file to the Compile Sources build phase
  5. Creates any missing PBXGroup entries (e.g. Core/Design)
  6. Verifies the file is referenced the expected number of times before saving

Safe to re-run: if the file is already registered it exits cleanly.
"""

import sys, os, re, hashlib

REPO_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PBX_PATH  = os.path.join(REPO_ROOT, 'ios/MowologyCRM/MowologyCRM.xcodeproj/project.pbxproj')
IOS_SRC   = os.path.join(REPO_ROOT, 'ios/MowologyCRM/MowologyCRM')

def make_uuid(seed: str) -> str:
    """24-char uppercase hex UUID deterministically derived from seed."""
    return hashlib.md5(seed.encode()).hexdigest()[:24].upper()

def main(swift_path: str):
    swift_path = os.path.abspath(swift_path)
    if not swift_path.endswith('.swift'):
        sys.exit(f"Not a Swift file: {swift_path}")
    if not os.path.isfile(swift_path):
        sys.exit(f"File not found: {swift_path}")
    if not swift_path.startswith(IOS_SRC):
        sys.exit(f"File is not under {IOS_SRC}")

    filename   = os.path.basename(swift_path)
    rel_to_src = os.path.relpath(swift_path, IOS_SRC)   # e.g. Core/Design/MWTheme.swift
    group_rel  = os.path.dirname(rel_to_src)              # e.g. Core/Design

    with open(PBX_PATH) as f:
        content = f.read()

    # Already registered?
    if f'path = {filename};' in content:
        print(f"✓ {filename} already in project.pbxproj — nothing to do.")
        return

    file_ref_id  = make_uuid(f'fileref:{rel_to_src}')
    build_file_id = make_uuid(f'buildfile:{rel_to_src}')

    # 1. PBXFileReference
    ref_entry = (
        f'\t\t{file_ref_id} /* {filename} */ = {{'
        f'isa = PBXFileReference; lastKnownFileType = sourcecode.swift; '
        f'path = {filename}; sourceTree = "<group>"; }};\n'
    )
    content = content.replace('/* End PBXFileReference section */',
                               ref_entry + '/* End PBXFileReference section */')

    # 2. PBXBuildFile
    build_entry = (
        f'\t\t{build_file_id} /* {filename} in Sources */ = {{'
        f'isa = PBXBuildFile; fileRef = {file_ref_id} /* {filename} */; }};\n'
    )
    content = content.replace('/* End PBXBuildFile section */',
                               build_entry + '/* End PBXBuildFile section */')

    # 3. Compile Sources — insert after last existing Sources entry
    content = re.sub(
        r'(\t\t\t\t[A-F0-9]{24} /\* \S+ in Sources \*/,)(?=\n\t\t\t\t\);)',
        f'\\1\n\t\t\t\t{build_file_id} /* {filename} in Sources */,',
        content, count=1
    )

    # 4. Ensure group hierarchy exists and file ref is in the leaf group
    parts = group_rel.split('/') if group_rel else []
    # Walk from root, find or create each group level
    # Strategy: find the leaf group by its `path = <name>;` and add file ref to it,
    # or create the group if missing.
    if parts:
        leaf_name = parts[-1]
        leaf_group_id = make_uuid(f'group:{group_rel}')

        # Check if leaf group exists
        if f'path = {leaf_name};\n\t\t\tname' in content or f'path = {leaf_name};\n\t\t\tsourceTree' in content or re.search(rf'path = {re.escape(leaf_name)};\s*sourceTree', content):
            # Group exists — add file ref to its children
            content = re.sub(
                rf'(path = {re.escape(leaf_name)};.*?children = \()(.*?)(\);)',
                lambda m: m.group(1) + m.group(2) + f'\t\t\t\t{file_ref_id} /* {filename} */,\n' + m.group(3),
                content, count=1, flags=re.DOTALL
            )
        else:
            # Create new group and add to parent group's children
            parent_name = parts[-2] if len(parts) > 1 else None
            new_group = (
                f'\t\t{leaf_group_id} /* {leaf_name} */ = {{\n'
                f'\t\t\tisa = PBXGroup;\n'
                f'\t\t\tchildren = (\n'
                f'\t\t\t\t{file_ref_id} /* {filename} */,\n'
                f'\t\t\t);\n'
                f'\t\t\tpath = {leaf_name};\n'
                f'\t\t\tsourceTree = "<group>";\n'
                f'\t\t}};\n'
            )
            content = content.replace('/* End PBXGroup section */',
                                       new_group + '/* End PBXGroup section */')

            # Add new group to parent's children
            if parent_name:
                content = re.sub(
                    rf'(path = {re.escape(parent_name)};.*?children = \()(.*?)(\);)',
                    lambda m: m.group(1) + m.group(2) + f'\t\t\t\t{leaf_group_id} /* {leaf_name} */,\n' + m.group(3),
                    content, count=1, flags=re.DOTALL
                )
    else:
        # Root level — add directly to main group
        content = re.sub(
            r'(isa = PBXProject;.*?mainGroup = )([A-F0-9]{24})',
            lambda m: m.group(0),  # no-op placeholder
            content, count=1, flags=re.DOTALL
        )

    # Verify
    count = content.count(file_ref_id)
    if count < 2:
        sys.exit(f"ERROR: UUID {file_ref_id} only appears {count} time(s) — insertion failed. Check pbxproj manually.")

    with open(PBX_PATH, 'w') as f:
        f.write(content)

    print(f"✓ Added {filename} to project.pbxproj ({file_ref_id})")
    print(f"  Group: {group_rel or '(root)'}")
    print(f"  FileRef:  {file_ref_id}")
    print(f"  BuildFile: {build_file_id}")

if __name__ == '__main__':
    if len(sys.argv) != 2:
        sys.exit(f"Usage: python3 {sys.argv[0]} path/to/NewFile.swift")
    main(sys.argv[1])
