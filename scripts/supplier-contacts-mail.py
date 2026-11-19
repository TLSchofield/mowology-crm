#!/usr/bin/env python3
"""Read local Mac Mail headers only and emit supplier contact sightings.

Bodies and subjects are never read or printed. The local JSON output is passed to
SupplierContactService::importMailSightings() through scripts/prod-run.sh.
"""
import argparse
import email.utils
import json
import sys
from email.parser import HeaderParser
from pathlib import Path


def read_headers(path):
    lines = []
    with path.open("r", encoding="utf-8", errors="replace") as handle:
        next(handle, None)  # .emlx byte count
        for line in handle:
            if line in ("\n", "\r\n"):
                break
            lines.append(line)
    return HeaderParser().parsestr("".join(lines))


def matching_domain(address, domains):
    host = address.rsplit("@", 1)[-1].lower()
    return next((domain for domain in domains if host == domain or host.endswith("." + domain)), None)


def main():
    parser = argparse.ArgumentParser(description="Find latest supplier From addresses in local Mac Mail headers")
    parser.add_argument("domains", type=Path)
    parser.add_argument("--mail-dir", type=Path, default=Path.home() / "Library/Mail/V10")
    args = parser.parse_args()
    domains = {line.strip().lower().lstrip("@") for line in args.domains.read_text().splitlines() if line.strip() and not line.lstrip().startswith("#")}
    latest = {}
    checked = 0
    for path in args.mail_dir.glob("**/*.emlx"):
        checked += 1
        try:
            headers = read_headers(path)
            senders = email.utils.getaddresses(headers.get_all("from", []))
            recipients = {address.lower() for _, address in email.utils.getaddresses(headers.get_all("to", []) + headers.get_all("cc", []))}
            moment = email.utils.parsedate_to_datetime(headers.get("date", ""))
            if moment is None:
                continue
            if moment.tzinfo is not None:
                moment = moment.astimezone().replace(tzinfo=None)
            date = moment.strftime("%Y-%m-%d %H:%M:%S")
            mailbox = "office@" if "office@mowology.ca" in recipients else "icloud" if "mowology@icloud.com" in recipients else "other"
            for person, address in senders:
                address = address.lower().strip()
                domain = matching_domain(address, domains)
                if not domain:
                    continue
                row = {"domain": domain, "email": address, "person": person or None, "date": date, "mailbox": mailbox}
                if address not in latest or date > latest[address]["date"]:
                    latest[address] = row
        except (OSError, ValueError, TypeError):
            continue
    print(json.dumps(sorted(latest.values(), key=lambda row: row["email"]), ensure_ascii=False))
    print(f"Checked {checked} mail files; found {len(latest)} latest supplier addresses.", file=sys.stderr)


if __name__ == "__main__":
    main()
