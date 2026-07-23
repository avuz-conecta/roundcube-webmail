import socket, time, sys, re

HOST, PORT = "127.0.0.1", 1143
USER, PASS = "spike@example.com", "spikepass"
FOLDERS = ["INBOX", "Sent", "Archive", "Junk", "Trash"]

class C:
    def __init__(self):
        self.s = socket.create_connection((HOST, PORT), timeout=20)
        self.s.settimeout(20)
        self.buf = b""
        self.readline()  # greeting
    def readline(self):
        while b"\r\n" not in self.buf:
            d = self.s.recv(65536)
            if not d: raise EOFError
            self.buf += d
        line, self.buf = self.buf.split(b"\r\n", 1)
        return line.decode(errors="replace")
    def send(self, raw):
        self.s.sendall(raw.encode())
    def read_until_tag(self, tag):
        lines = []
        while True:
            l = self.readline()
            lines.append(l)
            if l.startswith(tag + " "):
                return lines
    def cmd(self, tag, text):
        self.send(f"{tag} {text}\r\n")
        return self.read_until_tag(tag)

def login(c):
    r = c.cmd("l1", f"LOGIN {USER} {PASS}")
    assert r[-1].startswith("l1 OK"), r
    return c

def seed():
    c = login(C())
    for f in FOLDERS[1:]:
        c.cmd("s0", f"CREATE {f}")
    n = 0
    for f in FOLDERS:
        for i in range(6):
            subj = "needle-hit" if i % 3 == 0 else f"filler-{f}-{i}"
            msg = f"From: a@b.c\r\nTo: d@e.f\r\nSubject: {subj}\r\n\r\nbody {i}\r\n"
            c.send(f"a{n} APPEND {f} {{{len(msg)}}}\r\n")
            l = c.readline()
            assert l.startswith("+"), l
            c.send(msg + "\r\n")
            c.read_until_tag(f"a{n}")
            n += 1
    c.cmd("q", "LOGOUT")

def parse_search(lines):
    out = []
    for l in lines:
        m = re.match(r"^\* (?:E)?SEARCH(?: \(TAG [^)]*\))?(?: UID)?(.*)$", l)
        if m:
            out += [x for x in m.group(1).split() if x.isdigit()]
    return sorted(out, key=int)

def serial(c, term):
    t0 = time.time(); res = {}
    for i, f in enumerate(FOLDERS):
        c.cmd(f"x{i}s", f'SELECT "{f}"')
        r = c.cmd(f"x{i}q", f'UID SEARCH HEADER SUBJECT "{term}"')
        res[f] = parse_search(r)
    return time.time() - t0, res

def pipelined(c, term):
    t0 = time.time()
    out = "".join(f'p{i}s SELECT "{f}"\r\np{i}q UID SEARCH HEADER SUBJECT "{term}"\r\n'
                  for i, f in enumerate(FOLDERS))
    c.send(out)
    res = {}; order = []
    for i, f in enumerate(FOLDERS):
        sel = c.read_until_tag(f"p{i}s"); order.append(f"p{i}s")
        srch = c.read_until_tag(f"p{i}q"); order.append(f"p{i}q")
        assert sel[-1].startswith(f"p{i}s OK"), sel[-1]
        assert srch[-1].startswith(f"p{i}q OK"), srch[-1]
        res[f] = parse_search(srch)
    return time.time() - t0, res, order

if __name__ == "__main__":
    if "--seed" in sys.argv:
        seed(); print("seeded"); sys.exit()
    term = "needle-hit"
    c = login(C())
    ts, rs = serial(c, term)
    tp, rp, order = pipelined(c, term)
    print(f"serial    : {ts*1000:7.1f} ms  {rs}")
    print(f"pipelined : {tp*1000:7.1f} ms  {rp}")
    print(f"tag order : {' '.join(order)}")
    print("IDENTICAL :", rs == rp)
    c.cmd("q", "LOGOUT")
