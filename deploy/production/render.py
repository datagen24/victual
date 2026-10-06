#!/usr/bin/env python3
"""Turn deploy/production/values.yaml into a kustomize overlay on deploy/k3s.

    python3 deploy/production/render.py [values.yaml] [--out build/]

Writes the overlay to deploy/production/build/ (gitignored) and nothing else. deploy.sh
applies it. The overlay never copies a manifest: it lists deploy/k3s's files and patches
them, so what deploys is what the parity tests in .devtools/ci/ cover.

Every refusal names the field, so a half-filled values.yaml says what is missing rather
than deploying something that will not connect.
"""

from __future__ import annotations

import argparse
import os
import re
import shutil
import sys
from pathlib import Path

import yaml

HERE = Path(__file__).resolve().parent
PLACEHOLDER = "CHANGE-ME"

# Set from the sections of values.yaml, or fixed by this deployment: `settings` may not
# name them. BASE_PATH is a build input baked into the image's route cache, not a runtime
# setting (deploy/README.md), so it is refused rather than silently ignored.
RESERVED = re.compile(r"^(MODE|DB_.*|FILE_STORAGE|BASE_URL|BASE_PATH|MQTT_.*|INFLUXDB_.*)$")
SETTING_NAME = re.compile(r"^[A-Z][A-Z0-9_]*$")


class ValuesError(Exception):
    pass


def get(values: dict, path: str, *, required: bool = True, kind: type | tuple = str):
    """Read a dotted path from values, refusing a missing, mistyped or placeholder value."""
    node = values
    for part in path.split("."):
        if not isinstance(node, dict) or part not in node:
            if required:
                raise ValuesError(f"{path}: missing")
            return None
        node = node[part]
    if node is None:
        if required:
            raise ValuesError(f"{path}: missing")
        return None
    if not isinstance(node, kind) or (kind is int and isinstance(node, bool)):
        names = kind.__name__ if isinstance(kind, type) else " or ".join(k.__name__ for k in kind)
        raise ValuesError(f"{path}: expected {names}, got {type(node).__name__}")
    if isinstance(node, str):
        if PLACEHOLDER in node:
            raise ValuesError(f"{path}: still {node!r}; fill it in")
        if required and node == "":
            raise ValuesError(f"{path}: empty")
    return node


def text(value) -> str:
    """A ConfigMap value as Victual's ExternalSettingValue() reads it."""
    if isinstance(value, bool):
        return "true" if value else "false"
    return str(value)


def config_data(values: dict) -> dict[str, str]:
    host = get(values, "ingress.host")
    tls = get(values, "ingress.tls.enabled", kind=bool)
    data = {
        "VICTUAL_DB_HOST": get(values, "database.host"),
        "VICTUAL_DB_PORT": text(get(values, "database.port", kind=int)),
        "VICTUAL_DB_NAME": get(values, "database.name"),
        # Exactly what the Ingress publishes, scheme included.
        "VICTUAL_BASE_URL": f"{'https' if tls else 'http'}://{host}",
    }
    sslmode = get(values, "database.sslmode", required=False)
    allowed = {"disable", "allow", "prefer", "require", "verify-ca", "verify-full"}
    if sslmode:
        if sslmode not in allowed:
            raise ValuesError(f"database.sslmode: {sslmode!r} is not one of {sorted(allowed)}")
        data["VICTUAL_DB_SSLMODE"] = sslmode

    if get(values, "mqtt.enabled", kind=bool):
        data.update({
            "VICTUAL_MQTT_ENABLED": "true",
            "VICTUAL_MQTT_HOST": get(values, "mqtt.host"),
            "VICTUAL_MQTT_PORT": text(get(values, "mqtt.port", kind=int)),
            "VICTUAL_MQTT_TLS": text(get(values, "mqtt.tls", kind=bool)),
            "VICTUAL_MQTT_USERNAME": get(values, "mqtt.username", required=False) or "",
            "VICTUAL_MQTT_TOPIC_PREFIX": get(values, "mqtt.topicPrefix"),
            "VICTUAL_MQTT_DISCOVERY_PREFIX": get(values, "mqtt.discoveryPrefix"),
            "VICTUAL_MQTT_DISCOVERY_MODE": get(values, "mqtt.discoveryMode"),
            "VICTUAL_MQTT_DEVICE_NAME": get(values, "mqtt.deviceName"),
        })
        if data["VICTUAL_MQTT_DISCOVERY_MODE"] not in ("device", "entity"):
            raise ValuesError("mqtt.discoveryMode: must be device or entity")

    if get(values, "influxdb.enabled", kind=bool):
        data.update({
            "VICTUAL_INFLUXDB_ENABLED": "true",
            "VICTUAL_INFLUXDB_URL": get(values, "influxdb.url"),
            "VICTUAL_INFLUXDB_ORG": get(values, "influxdb.org"),
            "VICTUAL_INFLUXDB_BUCKET": get(values, "influxdb.bucket"),
        })

    for name, value in (get(values, "settings", required=False, kind=dict) or {}).items():
        if not SETTING_NAME.match(str(name)) or str(name).startswith("VICTUAL_"):
            raise ValuesError(f"settings.{name}: use the name from docs/manual/configuration.md, without VICTUAL_")
        if RESERVED.match(name):
            raise ValuesError(f"settings.{name}: set by another section of values.yaml or fixed by this deployment")
        if isinstance(value, (list, dict)) or value is None:
            raise ValuesError(f"settings.{name}: must be a single value; lists cannot be set from the environment")
        data[f"VICTUAL_{name}"] = text(value)
    return data


def secret_resources(values: dict, mqtt: bool, influx: bool) -> tuple[list[dict], list[dict]]:
    """Resources to add and patches to apply for the two Secrets the base names."""
    source = get(values, "secrets.source")
    if source == "onepassword":
        vault = get(values, "secrets.onepassword.vault")
        items = []
        for secret, field in (("victual-db-migrate", "migrateItem"), ("victual-db-app", "appItem")):
            item = get(values, f"secrets.onepassword.{field}")
            items.append({
                "apiVersion": "onepassword.com/v1",
                "kind": "OnePasswordItem",
                "metadata": {"name": secret},
                "spec": {"itemPath": f"vaults/{vault}/items/{item}"},
            })
        # The base's placeholder Secrets go, so an apply cannot overwrite what the
        # Connect operator wrote.
        deletes = [
            {"$patch": "delete", "apiVersion": "v1", "kind": "Secret", "metadata": {"name": name}}
            for name in ("victual-db-migrate", "victual-db-app")
        ]
        return items, deletes
    if source == "inline":
        migrate = {
            "VICTUAL_DB_USER": "victual_migrate",
            "VICTUAL_DB_PASSWORD": get(values, "secrets.inline.migratePassword"),
            "VICTUAL_BOOTSTRAP_ADMIN_PASSWORD": get(values, "secrets.inline.bootstrapAdminPassword"),
        }
        app = {
            "VICTUAL_DB_USER": "victual_app",
            "VICTUAL_DB_PASSWORD": get(values, "secrets.inline.appPassword"),
        }
        if mqtt and get(values, "mqtt.username", required=False):
            app["VICTUAL_MQTT_PASSWORD"] = get(values, "secrets.inline.mqttPassword")
        if influx:
            app["VICTUAL_INFLUXDB_TOKEN"] = get(values, "secrets.inline.influxdbToken")
        patches = [
            {"apiVersion": "v1", "kind": "Secret", "metadata": {"name": name}, "stringData": data}
            for name, data in (("victual-db-migrate", migrate), ("victual-db-app", app))
        ]
        return [], patches
    raise ValuesError(f"secrets.source: {source!r} is not onepassword or inline")


def ingress(values: dict) -> dict:
    host = get(values, "ingress.host")
    spec: dict = {
        "ingressClassName": get(values, "ingress.className"),
        "rules": [{
            "host": host,
            "http": {"paths": [{
                "path": "/",
                "pathType": "Prefix",
                "backend": {"service": {"name": "victual", "port": {"name": "http"}}},
            }]},
        }],
    }
    if get(values, "ingress.tls.enabled", kind=bool):
        tls: dict = {"hosts": [host]}
        secret_name = get(values, "ingress.tls.secretName", required=False)
        tls["secretName"] = secret_name or "victual-tls"
        spec["tls"] = [tls]
    metadata: dict = {"name": "victual", "labels": {"app.kubernetes.io/name": "victual"}}
    annotations = get(values, "ingress.annotations", required=False, kind=dict)
    if annotations:
        metadata["annotations"] = {str(k): text(v) for k, v in annotations.items()}
    return {"apiVersion": "networking.k8s.io/v1", "kind": "Ingress", "metadata": metadata, "spec": spec}


def render(values: dict, out: Path) -> dict[str, str]:
    namespace = get(values, "cluster.namespace")
    tag = get(values, "image.tag")
    mqtt = get(values, "mqtt.enabled", kind=bool)
    influx = get(values, "influxdb.enabled", kind=bool)
    mcp = get(values, "mcp.enabled", kind=bool)
    data = config_data(values)
    items, secret_patches = secret_resources(values, mqtt, influx)

    if out.exists():
        shutil.rmtree(out)
    out.mkdir(parents=True)
    # Relative, so the overlay names deploy/k3s wherever --out puts it.
    k3s = Path(os.path.relpath(HERE.parent / "k3s", out.resolve()))

    def write(name: str, docs) -> str:
        (out / name).write_text(yaml.safe_dump_all(docs if isinstance(docs, list) else [docs], sort_keys=False))
        return name

    resources = [
        write("namespace.yaml", {"apiVersion": "v1", "kind": "Namespace", "metadata": {"name": namespace}}),
        str(k3s / "victual.yaml"),
    ]
    if mcp:
        resources.append(str(k3s / "victual-mcp.yaml"))
    resources.append(write("ingress.yaml", ingress(values)))
    if items:
        resources.append(write("onepassword-items.yaml", items))

    patches = [{"path": write("config.yaml", {
        "apiVersion": "v1", "kind": "ConfigMap", "metadata": {"name": "victual-config"}, "data": data,
    })}]
    for patch in secret_patches:
        patches.append({"path": write(f"secret-{patch['metadata']['name']}.yaml", patch)})

    images = ["victual-app", "victual-web", "victual-migrate"] + (["victual-mcp"] if mcp else [])
    write("kustomization.yaml", {
        "apiVersion": "kustomize.config.k8s.io/v1beta1",
        "kind": "Kustomization",
        "namespace": namespace,
        "resources": resources,
        "images": [{"name": f"ghcr.io/datagen24/{image}", "newTag": tag} for image in images],
        "patches": patches,
    })

    deploy_env = {
        "CONTEXT": get(values, "cluster.context", required=False) or "",
        "NAMESPACE": namespace,
        "MCP": "1" if mcp else "",
        "ONEPASSWORD": "1" if items else "",
        "URL": data["VICTUAL_BASE_URL"],
    }
    (out / "deploy.env").write_text("".join(f"{k}='{v}'\n" for k, v in deploy_env.items()))
    return deploy_env


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("values", nargs="?", default=str(HERE / "values.yaml"))
    parser.add_argument("--out", default=str(HERE / "build"))
    args = parser.parse_args()

    path = Path(args.values)
    if not path.exists():
        print(f"{path}: not found. Start from deploy/production/values.example.yaml.", file=sys.stderr)
        return 2
    values = yaml.safe_load(path.read_text()) or {}
    try:
        env = render(values, Path(args.out))
    except ValuesError as error:
        print(f"{path}: {error}", file=sys.stderr)
        return 1
    print(f"Rendered {args.out} for {env['URL']} in namespace {env['NAMESPACE']}.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
