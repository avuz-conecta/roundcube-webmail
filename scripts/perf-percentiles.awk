#!/usr/bin/awk -f
# Reads nginx "perf" log lines on stdin:
#   <msec> <request_time> <upstream_response_time> <status> <method> <request_uri>
# Buckets request_time by the Roundcube _action in the URI and prints
# count / p50 / p95 / p99 / max per action, slowest p95 first.
{
    rt = $2 + 0
    uri = $6
    act = "other"
    if (match(uri, /_action=[^&]+/)) {
        act = substr(uri, RSTART + 8, RLENGTH - 8)
    } else if (match(uri, /_task=[^&]+/)) {
        act = "task:" substr(uri, RSTART + 6, RLENGTH - 6)
    }
    n[act]++
    times[act, n[act]] = rt
}
function pct(a, cnt, p,   idx) {
    idx = int((p / 100.0) * cnt + 0.5)
    if (idx < 1) idx = 1
    if (idx > cnt) idx = cnt
    return a[idx]
}
END {
    printf "%-28s %7s %8s %8s %8s %8s\n", "action", "count", "p50", "p95", "p99", "max"
    for (act in n) {
        cnt = n[act]
        for (i = 1; i <= cnt; i++) col[i] = times[act, i]
        # insertion sort (log volumes are small; keeps the script dependency-free)
        for (i = 2; i <= cnt; i++) {
            v = col[i]; j = i - 1
            while (j >= 1 && col[j] > v) { col[j+1] = col[j]; j-- }
            col[j+1] = v
        }
        rows[act] = sprintf("%-28s %7d %8.3f %8.3f %8.3f %8.3f", act, cnt, pct(col,cnt,50), pct(col,cnt,95), pct(col,cnt,99), col[cnt])
        p95sort[act] = pct(col, cnt, 95)
        delete col
    }
    # print slowest p95 first
    for (act in p95sort) order[++m] = act
    for (i = 2; i <= m; i++) { v = order[i]; j = i-1; while (j>=1 && p95sort[order[j]] < p95sort[v]) { order[j+1]=order[j]; j-- } order[j+1]=v }
    for (i = 1; i <= m; i++) print rows[order[i]]
}
