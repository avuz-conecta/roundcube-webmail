#!/usr/bin/awk -f
# Reads nginx "perf" log lines:
#   <msec> <request_time> <upstream_response_time> <status> <method> <request_uri>
# Reconstructs each request's [start,end] interval (start = msec - request_time),
# then splits list/show request_time into "during prefetch" vs "prefetch idle" by
# whether any plugin.avuz_prefetch request's interval overlapped it. Prints
# count/p50/p95/p99 per bucket. If the two buckets for an action match, foreground
# latency is independent of prefetch — the criterion is met.
{
    end = $1 + 0; rt = $2 + 0; start = end - rt
    uri = $6; act = "other"
    if (match(uri, /_action=[^&]+/)) act = substr(uri, RSTART+8, RLENGTH-8)
    if (act == "plugin.avuz_prefetch") { pf++; pf_s[pf] = start; pf_e[pf] = end; next }
    if (act == "list" || act == "show") { fg++; fa[fg] = act; fs[fg] = start; fe[fg] = end; fr[fg] = rt }
}
function pct(a, cnt, p,   idx) { idx = int((p/100.0)*cnt + 0.5); if (idx<1) idx=1; if (idx>cnt) idx=cnt; return a[idx] }
END {
    for (k = 1; k <= fg; k++) {
        ov = 0
        for (p = 1; p <= pf; p++) if (fs[k] < pf_e[p] && pf_s[p] < fe[k]) { ov = 1; break }
        b = fa[k] (ov ? " |during-prefetch" : " |prefetch-idle")
        n[b]++; t[b, n[b]] = fr[k]
    }
    printf "%-26s %7s %8s %8s %8s\n", "bucket", "count", "p50", "p95", "p99"
    for (b in n) {
        c = n[b]; for (i=1;i<=c;i++) col[i]=t[b,i]
        for (i=2;i<=c;i++){ v=col[i]; j=i-1; while(j>=1&&col[j]>v){col[j+1]=col[j];j--} col[j+1]=v }
        printf "%-26s %7d %8.3f %8.3f %8.3f\n", b, c, pct(col,c,50), pct(col,c,95), pct(col,c,99)
        delete col
    }
}
