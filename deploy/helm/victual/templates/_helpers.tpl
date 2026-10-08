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
