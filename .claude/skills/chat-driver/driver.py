#!/usr/bin/env python3
"""Drives `bin/agentic chat` in a pseudo-terminal: sends lines, waits for patterns, prints what appeared.

Usage: driver.py [--real-model] [--cwd DIR] [--before ANSWER] [--size COLSxROWS] [--record FILE] STEPS_JSON

STEPS_JSON is a list of steps: {"send": "text", "until": "regex", "timeout": 60, "settle": 2}.
`send` is typed then Enter; "" sends Enter alone (picks the first choice of a list).
{"keys": ["down", "down", "enter"], "until": ...} presses keys (up, down, enter, shift+tab, esc), one per
0.4 s. {"choose": "regex", "until": ...} moves down a list until the picked row (→) matches — or back
to the first after a full turn —, then Enter. `until` is searched in what the step printed. Any step may carry "pause": seconds to wait before it, for a recording to breathe.
{"click": "regex", "until": ...} clicks (left button) the lowest screen row matching the regex,
then prints the screen as it stands.
Without --real-model, MISTRAL_API_KEY is forced empty: the scripted client answers, no network.
--cwd DIR launches the chat from DIR: the project the agent works on (default: this repository).
--size COLSxROWS sets the terminal (default 140x50).
--record FILE writes the session as an asciicast v2 (asciinema, agg), typing at a human pace.
--before ANSWER answers the question asked before the chat opens — the approval of a project's
.agentic/config.* — then waits for the chat.
"""
import codecs, fcntl, json, os, pty, re, select, struct, sys, termios, time

PROJECT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', '..'))
# Cursor moves become line breaks — a redraw is a new line, not a continuation —; the rest goes.
MOVES = re.compile(r'\x1b\[[0-9;]*[HfABCDGJK]')
ANSI = re.compile(r'\x1b\[[0-9;?<>=]*[ -/]*[@-~]|\x1b\][^\x07]*\x07|\x1b[()][A-Z0-9]|\x1b[=>78]|\r')

args = sys.argv[1:]
real = '--real-model' in args
args = [a for a in args if a != '--real-model']
options = {}
for name in ('--cwd', '--before', '--record', '--size'):
    if name in args:
        i = args.index(name)
        options[name] = args[i + 1]
        del args[i:i + 2]
if len(args) != 1:
    sys.exit(__doc__)
steps = json.loads(args[0])
cols, lines = map(int, options.get('--size', '140x50').split('x'))
launch_dir = os.path.abspath(options.get('--cwd', PROJECT))

pid, fd = pty.fork()
if pid == 0:
    os.chdir(launch_dir)
    os.environ['TERM'] = 'xterm-256color'
    if not real:
        # .env.local carries a real key; an empty variable wins over it.
        os.environ['MISTRAL_API_KEY'] = ''
    os.execvp('php8.4', ['php8.4', os.path.join(PROJECT, 'bin/agentic'), 'chat'])

fcntl.ioctl(fd, termios.TIOCSWINSZ, struct.pack('HHHH', lines, cols, 0, 0))
raw = b''
KEYS = {'up': '\x1b[A', 'down': '\x1b[B', 'enter': '\r', 'shift+tab': '\x1b[Z', 'esc': '\x1b'}
record = open(options['--record'], 'w') if '--record' in options else None
if record:
    record.write(json.dumps({'version': 2, 'width': cols, 'height': lines, 'env': {'TERM': 'xterm-256color'}}) + '\n')
began = time.time()
decoder = codecs.getincrementaldecoder('utf-8')('replace')  # a chunk can end mid-character


def pump(seconds):
    global raw
    end = time.time() + seconds
    while time.time() < end:
        ready, _, _ = select.select([fd], [], [], 0.2)
        if ready:
            try:
                chunk = os.read(fd, 65536)
            except OSError:
                return False
            if not chunk:
                return False
            raw += chunk
            if record:
                record.write(json.dumps([round(time.time() - began, 3), 'o', decoder.decode(chunk)]) + '\n')
    return True


def text():
    return ANSI.sub('', MOVES.sub('\n', raw.decode('utf-8', 'replace')))


def wait_for(pattern, timeout, since=0):
    end = time.time() + timeout
    while time.time() < end:
        if re.search(pattern, text()[since:]):
            return True
        if not pump(0.5):
            return False
    return False


SEQUENCE = re.compile(r'\x1b\[([0-9;?]*)([ -/]*[@-~])|\x1b\][^\x07]*\x07|\x1b[()][A-Z0-9]|\x1b[=>78]|[\r\n]|[^\x1b\r\n]+')


def screen():
    """The rows on screen now, replayed from the moves the renderer uses: home, clear, up, down,
    column, erase line. Wide characters count as one column: good enough to find a row."""
    rows, row, col = {}, 0, 0
    for m in SEQUENCE.finditer(raw.decode('utf-8', 'replace')):
        token, params, final = m.group(0), m.group(1), m.group(2)
        if final:
            n = int(params) if params.isdigit() else 1
            if final == 'H':
                row, col = 0, 0
            elif final == 'J' and params in ('2', '3'):
                rows = {}
            elif final == 'A':
                row = max(0, row - n)
            elif final == 'B':
                row += n
            elif final == 'G':
                col = n - 1
            elif final == 'K':
                rows[row] = rows.get(row, '')[:col] if params == '' else ''
        elif token == '\r':
            col = 0
        elif token == '\n':
            row += 1
        elif not token.startswith('\x1b'):
            line = rows.get(row, '').ljust(col)
            rows[row] = line[:col] + token + line[col + len(token):]
            col += len(token)
    return [rows.get(r, '') for r in range(max(rows, default=-1) + 1)]


def readable(chunk):
    """The lines of a chunk, deduplicated, without the keystroke-by-keystroke echo of the input."""
    lines = []
    for line in (l.strip() for l in chunk.split('\n')):
        if line and line not in lines:
            lines.append(line)
    typed = [l for l in lines if l.startswith('›')]
    return [l for l in lines if not (l.startswith('›') and any(o != l and o.startswith(l) for o in typed))]


pump(4)
if '--before' in options:
    before_start = len(text())
    wait_for(r'\?', 20)
    for ch in options['--before']:
        os.write(fd, ch.encode())
    os.write(fd, b'\r')
    pump(2)
    print('=== BEFORE THE CHAT:')
    print('\n'.join(readable(text()[before_start:])[-40:]))
if not wait_for(r'your turn', 20):
    print('=== the chat did not open:\n' + text()[-2000:])
    os.write(fd, b'\x03')
    sys.exit(1)

failed = False
for step in steps:
    pump(step.get('pause', 0))
    start = len(text())
    if 'keys' in step:
        for key in step['keys']:
            os.write(fd, KEYS[key].encode())
            pump(0.4)
    elif 'choose' in step:
        mark, seen = 0, []  # the list was drawn before the step began
        while True:
            picked = [l.strip() for l in text()[mark:].split('\n') if l.lstrip().startswith('→')]
            # A full turn of the list without a match leaves the first row picked.
            if picked and (re.search(step['choose'], picked[-1]) or picked[-1] in seen):
                break
            seen += picked[-1:]
            mark = len(text())
            os.write(fd, KEYS['down'].encode())
            pump(0.6)
        os.write(fd, b'\r')
    elif 'click' in step:
        rows = [r for r, line in enumerate(screen()) if re.search(step['click'], line)]
        if not rows:
            failed = True
            print('=== CLICK: %r is not on screen' % step['click'])
            continue
        # SGR 1006, 1-based: press then release of the left button.
        os.write(fd, ('\x1b[<0;10;%dM\x1b[<0;10;%dm' % (rows[-1] + 1, rows[-1] + 1)).encode())
    else:
        for ch in step['send']:
            os.write(fd, ch.encode())
            pump(0.05) if record else time.sleep(0.01)
        os.write(fd, b'\r')
    ok = wait_for(step['until'], step.get('timeout', 60), start)
    pump(step.get('settle', 2))
    failed = failed or not ok
    what = 'KEYS %r' % step['keys'] if 'keys' in step else 'CHOSE %r' % step['choose'] if 'choose' in step else 'CLICKED %r (row %d)' % (step['click'], rows[-1]) if 'click' in step else 'SENT: %r' % step['send']
    print('=== %s %s' % (what, 'matched' if ok else 'TIMEOUT waiting for ' + step['until']))
    if 'click' in step:
        print('\n'.join(line.rstrip() for line in screen()))
    else:
        print('\n'.join(readable(text()[start:])[-80:]))

if record:
    record.close()  # the recording ends on the last answer, not on the exit
    record = None
os.write(fd, b'\x03')
pump(3)
conversation = re.search(r'Conversation ([0-9a-f-]{36})', text())
print('=== conversation:', conversation.group(1) if conversation else '(not printed)')
sys.exit(1 if failed else 0)
