import sys; sys.path.insert(0, "."); import pipe_test as T
T.PORT = 1143
c = T.login(T.C())
c.send('f0s SELECT "INBOX"\r\nf0q UID SEARCH HEADER SUBJECT "needle-hit"\r\n'
       'f1s SELECT "DoesNotExist"\r\nf1q UID SEARCH HEADER SUBJECT "needle-hit"\r\n'
       'f2s SELECT "F001"\r\nf2q UID SEARCH HEADER SUBJECT "needle-hit"\r\n')
for t in ["f0s","f0q","f1s","f1q","f2s","f2q"]:
    lines = c.read_until_tag(t)
    print(f"{t}: {lines[-1]}   data={[l for l in lines[:-1] if 'SEARCH' in l]}")
c.cmd("q", "LOGOUT")
