{{- /*
An image reference: the registry, the image's name, and the tag, which defaults to the
chart's appVersion (ADR-0038 decision 4).
*/ -}}
{{- define "victual.image" -}}
{{- printf "%s/%s:%s" .root.Values.image.registry .name (.root.Values.image.tag | default .root.Chart.AppVersion) -}}
{{- end -}}

{{- /* The URL Victual is served at: baseUrl, or what the Ingress publishes. */ -}}
{{- define "victual.baseUrl" -}}
{{- if .Values.baseUrl -}}
{{- .Values.baseUrl -}}
{{- else if and .Values.ingress.enabled .Values.ingress.host -}}
{{- printf "%s://%s" (ternary "https" "http" .Values.ingress.tls.enabled) .Values.ingress.host -}}
{{- else -}}
{{- fail "baseUrl: empty, and there is no ingress.host to derive it from" -}}
{{- end -}}
{{- end -}}

{{- /*
Whether the pods need the MQTT password and the InfluxDB token. An anonymous broker has no
password to hold.
*/ -}}
{{- define "victual.wantsMqttSecret" -}}
{{- if and .Values.mqtt.enabled .Values.mqtt.username }}true{{ end -}}
{{- end -}}
{{- define "victual.wantsInfluxdbSecret" -}}
{{- if .Values.influxdb.enabled }}true{{ end -}}
{{- end -}}

{{- /* A ConfigMap value as Victual's ExternalSettingValue() reads it. */ -}}
{{- define "victual.setting" -}}
{{- if kindIs "bool" . -}}{{ ternary "true" "false" . }}{{- else if kindIs "float64" . -}}{{ . | toString }}{{- else -}}{{ . }}{{- end -}}
{{- end -}}

{{- /*
Whether a credential Secret is rendered, given secrets.source. Called with a dict:
  root:        the chart's root context
  placeholder: whether placeholder mode renders it (the base carries a placeholder)
existing renders nothing, because the Secret is the operator's; placeholder renders only
the Secrets the base has always carried. A template that calls this has already decided
the Secret is wanted (its feature is enabled).
*/ -}}
{{- define "victual.secretRendered" -}}
{{- $source := .root.Values.secrets.source -}}
{{- if or (eq $source "inline") (eq $source "onepassword") (and (eq $source "placeholder") .placeholder) }}true{{ end -}}
{{- end -}}

{{- /*
A credential Secret's document, or the OnePasswordItem the 1Password operator turns into
it. Called with a dict:
  root:   the chart's root context
  key:    its key under secrets.names and secrets.onepassword.items
  data:   its keys and values: the inline ones in inline mode, the placeholders otherwise
  labels: whether it carries app.kubernetes.io/name: victual
*/ -}}
{{- define "victual.secretBody" -}}
{{- $s := .root.Values.secrets -}}
{{- $name := index $s.names .key -}}
{{- if eq $s.source "onepassword" -}}
apiVersion: onepassword.com/v1
kind: OnePasswordItem
metadata:
  name: {{ $name }}
  {{- if .labels }}
  labels:
    app.kubernetes.io/name: victual
  {{- end }}
spec:
  itemPath: {{ printf "vaults/%s/items/%s" $s.onepassword.vault (index $s.onepassword.items .key) | quote }}
{{- else -}}
apiVersion: v1
kind: Secret
metadata:
  name: {{ $name }}
  {{- if .labels }}
  labels:
    app.kubernetes.io/name: victual
  {{- end }}
type: Opaque
stringData:
  {{- range $k, $v := .data }}
  {{ $k }}: {{ $v | quote }}
  {{- end }}
{{- end -}}
{{- end -}}

{{- /*
The data of the victual-config ConfigMap. A define so that the Deployment can hash exactly
what the ConfigMap holds: a change here must roll the pods, because envFrom is read once, at
container start (issue #852).
*/ -}}
{{- define "victual.config" -}}
VICTUAL_MODE: "production"
VICTUAL_DB_DRIVER: "pgsql"
# The operator's database. Not `victual-db` or anything else this file could invent: where
# PostgreSQL lives is not this manifest's decision.
VICTUAL_DB_HOST: {{ .Values.database.host | quote }}
VICTUAL_DB_PORT: {{ .Values.database.port | toString | quote }}
VICTUAL_DB_NAME: {{ .Values.database.name | quote }}
{{- with .Values.database.sslmode }}
VICTUAL_DB_SSLMODE: {{ . | quote }}
{{- end }}
# There is nothing writable mounted, so the default `filesystem` backend has nowhere to
# write; see deploy/README.md.
VICTUAL_FILE_STORAGE: "database"
# What the ingress publishes.
VICTUAL_BASE_URL: {{ include "victual.baseUrl" . | quote }}
{{- with .Values.mqtt }}
{{- if .enabled }}
VICTUAL_MQTT_ENABLED: "true"
VICTUAL_MQTT_HOST: {{ .host | quote }}
VICTUAL_MQTT_PORT: {{ .port | toString | quote }}
VICTUAL_MQTT_TLS: {{ ternary "true" "false" .tls | quote }}
VICTUAL_MQTT_USERNAME: {{ .username | quote }}
VICTUAL_MQTT_TOPIC_PREFIX: {{ .topicPrefix | quote }}
VICTUAL_MQTT_DISCOVERY_PREFIX: {{ .discoveryPrefix | quote }}
VICTUAL_MQTT_DISCOVERY_MODE: {{ .discoveryMode | quote }}
VICTUAL_MQTT_DEVICE_NAME: {{ .deviceName | quote }}
{{- end }}
{{- end }}
{{- with .Values.influxdb }}
{{- if .enabled }}
VICTUAL_INFLUXDB_ENABLED: "true"
VICTUAL_INFLUXDB_VERSION: {{ .version | toString | quote }}
VICTUAL_INFLUXDB_URL: {{ .url | quote }}
VICTUAL_INFLUXDB_ORG: {{ .org | quote }}
VICTUAL_INFLUXDB_BUCKET: {{ .bucket | quote }}
VICTUAL_INFLUXDB_USERNAME: {{ .username | quote }}
{{- end }}
{{- end }}
{{- range $name, $value := .Values.settings }}
{{ printf "VICTUAL_%s" $name }}: {{ include "victual.setting" $value | quote }}
{{- end }}
# VICTUAL_DB_USER is deliberately not here. It arrives with the password, from a Secret
# per workload, so that which role a container holds is a property of which Secret it
# names and cannot be overridden by list order.
{{- end -}}

{{- /*
The credential Secrets (or OnePasswordItems) this chart renders, as the documents that follow
a `---`. A define so that the Deployment can hash exactly what the Secrets hold (issue #852).
*/ -}}
{{- define "victual.credentialSecrets" -}}
{{- $inline := eq .Values.secrets.source "inline" }}
{{- if include "victual.secretRendered" (dict "root" . "placeholder" true) }}
---
# The role that owns the schema: held by the migrate initContainer and by nothing else.
{{ include "victual.secretBody" (dict "root" . "key" "migrate" "labels" true "data" (dict
    "VICTUAL_DB_USER" "victual_migrate"
    "VICTUAL_DB_PASSWORD" (ternary (.Values.secrets.inline.migratePassword | default "") "replace-me-with-the-migrate-role-password" $inline))) }}
---
# The role that reads and writes rows and cannot change structure: held by php-fpm.
{{ include "victual.secretBody" (dict "root" . "key" "app" "labels" true "data" (dict
    "VICTUAL_DB_USER" "victual_app"
    "VICTUAL_DB_PASSWORD" (ternary (.Values.secrets.inline.appPassword | default "") "replace-me-with-the-app-role-password" $inline))) }}
---
# The first administrator's password, read once by the migrate initContainer when it creates
# the database. Blank means unset: the first migration generates a password, prints it once
# to the migrate container's log and forces a change at first login. Not a placeholder
# password, because a known value here would be every forgetful deployment's admin password.
# Its own Secret, not a key in victual-db-migrate: a database Secret holds the database
# credential and nothing else, so rotating it rewrites nothing unrelated (plan 36).
{{ include "victual.secretBody" (dict "root" . "key" "bootstrapAdmin" "labels" true "data" (dict
    "VICTUAL_BOOTSTRAP_ADMIN_PASSWORD" (ternary (.Values.secrets.inline.bootstrapAdminPassword | default "") "" $inline))) }}
{{- end }}
{{- if and (include "victual.wantsMqttSecret" .) (include "victual.secretRendered" (dict "root" . "placeholder" false)) }}
---
# The MQTT broker's password, for a broker that has a username.
{{ include "victual.secretBody" (dict "root" . "key" "mqtt" "labels" true "data" (dict
    "VICTUAL_MQTT_PASSWORD" (.Values.secrets.inline.mqttPassword | default ""))) }}
{{- end }}
{{- if and (include "victual.wantsInfluxdbSecret" .) (include "victual.secretRendered" (dict "root" . "placeholder" false)) }}
---
# The InfluxDB v2 token.
{{ include "victual.secretBody" (dict "root" . "key" "influxdb" "labels" true "data" (dict
    "VICTUAL_INFLUXDB_TOKEN" (.Values.secrets.inline.influxdbToken | default ""))) }}
{{- end }}
{{- end -}}

{{- /* The data of the victual-mcp-config ConfigMap; see victual.config. */ -}}
{{- define "victual.mcpConfig" -}}
# Victual's own Service, from deploy/k3s/victual.yaml — in-cluster HTTP by design (§8:
# TLS is the ingress's job; the sidecar never disables verification, it just never
# leaves the cluster network).
VICTUAL_BASE_URL: "http://victual:8080"
# mcp.enabledTools: `all-read` is the read tools, `stock-write` the stock and list writes,
# `catalog-write` the product, location, unit, group and barcode writes.
# A comma-separated list names tools one by one; an unknown name stops the container at
# startup rather than being ignored.
MCP_ENABLED_TOOLS: {{ .Values.mcp.enabledTools | default "all-read" | quote }}
MCP_REQUEST_TIMEOUT_MS: "10000"
LOG_LEVEL: "info"
{{- end -}}
