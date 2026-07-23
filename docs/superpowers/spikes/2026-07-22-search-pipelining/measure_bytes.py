"""How many reply bytes does one pipelined batch buffer? That bound sets the safe chunk size."""
import sys; sys.path.insert(0, "."); import pipe_test as T
T.PORT = 1143
FOLDERS = ["INBOX"] + [f"F{i:03d}" for i in range(1, 107)]
c = T.login(T.C())
out = "".join(f'p{i}s SELECT "{f}"\r\np{i}q UID SEARCH RETURN (ALL) HEADER SUBJECT "needle-hit"\r\n'
              for i, f in enumerate(FOLDERS))
c.send(out)
total = 0
for i, f in enumerate(FOLDERS):
    for t in (f"p{i}s", f"p{i}q"):
        total += sum(len(l) + 2 for l in c.read_until_tag(t))
print(f"folders={len(FOLDERS)}  reply bytes={total}  per folder={total/len(FOLDERS):.0f}")
print(f"commands written={len(out)} bytes")
c.cmd("q", "LOGOUT")
