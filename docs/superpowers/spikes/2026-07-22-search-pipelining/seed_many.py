import sys
sys.path.insert(0, ".")
import pipe_test as T
T.PORT = 1143
T.FOLDERS = ["INBOX"] + [f"F{i:03d}" for i in range(1, 107)]
c = T.login(T.C())
for f in T.FOLDERS[1:]:
    c.cmd("s0", f"CREATE {f}")
n = 0
for f in T.FOLDERS:
    msg = "From: a@b.c\r\nTo: d@e.f\r\nSubject: needle-hit\r\n\r\nbody\r\n"
    c.send(f"a{n} APPEND {f} {{{len(msg)}}}\r\n")
    assert c.readline().startswith("+")
    c.send(msg + "\r\n"); c.read_until_tag(f"a{n}"); n += 1
c.cmd("q", "LOGOUT")
print("seeded", len(T.FOLDERS), "folders")
