#!/usr/bin/env python3
import json
import os
import sys
import time
from pathlib import Path

import requests


API = "https://spaceship.dev/api/v1"
ENV_PATH = Path(os.getenv("SPACESHIP_ENV_PATH", "/root/.config/spaceship/env"))


def load_env() -> dict[str, str]:
    data: dict[str, str] = {}
    for line in ENV_PATH.read_text().splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        data[key] = value.strip().strip('"').strip("'")
    return data


def headers(env: dict[str, str]) -> dict[str, str]:
    return {
        "X-API-Key": env["SPACESHIP_API_KEY"],
        "X-API-Secret": env["SPACESHIP_API_SECRET"],
        "Content-Type": "application/json",
    }


def rr_name_for_certbot_domain(certbot_domain: str, zone: str) -> str:
    suffix = "." + zone
    domain = certbot_domain.lstrip("*.")
    if domain == zone:
        return "_acme-challenge"
    if domain.endswith(suffix):
        relative = domain[: -len(suffix)]
        return "_acme-challenge." + relative
    raise SystemExit(f"certbot domain {certbot_domain} is outside zone {zone}")


def get_records(env: dict[str, str]) -> list[dict]:
    zone = env["SPACESHIP_DOMAIN"]
    response = requests.get(
        f"{API}/dns/records/{zone}",
        params={"take": 500, "skip": 0, "orderBy": "name"},
        headers=headers(env),
        timeout=30,
    )
    response.raise_for_status()
    return response.json().get("items", [])


def add_txt(env: dict[str, str], name: str, value: str) -> None:
    zone = env["SPACESHIP_DOMAIN"]
    payload = {
        "force": True,
        "items": [{"type": "TXT", "name": name, "value": value, "ttl": 60}],
    }
    response = requests.put(
        f"{API}/dns/records/{zone}",
        headers=headers(env),
        data=json.dumps(payload),
        timeout=30,
    )
    if response.status_code != 204:
        raise SystemExit(f"add TXT failed: HTTP {response.status_code} {response.text[:300]}")


def delete_txt(env: dict[str, str], name: str, value: str) -> None:
    zone = env["SPACESHIP_DOMAIN"]
    payload = [{"type": "TXT", "name": name, "value": value}]
    response = requests.delete(
        f"{API}/dns/records/{zone}",
        headers=headers(env),
        data=json.dumps(payload),
        timeout=30,
    )
    if response.status_code not in (200, 204, 404):
        raise SystemExit(f"delete TXT failed: HTTP {response.status_code} {response.text[:300]}")


def main() -> None:
    if len(sys.argv) != 2 or sys.argv[1] not in {"auth", "cleanup"}:
        raise SystemExit("usage: spaceship_dns.py auth|cleanup")

    env = load_env()
    certbot_domain = os.environ["CERTBOT_DOMAIN"]
    validation = os.environ["CERTBOT_VALIDATION"]
    name = rr_name_for_certbot_domain(certbot_domain, env["SPACESHIP_DOMAIN"])

    if sys.argv[1] == "auth":
        add_txt(env, name, validation)
        time.sleep(int(os.environ.get("SPACESHIP_PROPAGATION_SECONDS", "90")))
    else:
        delete_txt(env, name, validation)


if __name__ == "__main__":
    main()
