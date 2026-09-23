#!/usr/bin/env python3
"""Drives `bin/agentic chat` in a pseudo-terminal: sends lines, waits for patterns, prints what appeared.

Usage: driver.py [--real-model] [--cwd DIR] [--before ANSWER] STEPS_JSON

STEPS_JSON is a list of steps: {"send": "text", "until": "regex", "timeout": 60, "settle": 2}.
`send` is typed then Enter; "" sends Enter alone (picks the first choice of a list).
Without --real-model, MISTRAL_API_KEY is forced empty: the scripted client answers, no network.
--cwd DIR launches the chat from DIR: the project the agent works on (default: this repository).
--before ANSWER answers the question asked before the chat opens — the approval of a project's
.agentic/config.* — then waits for the chat.
"""
import fcntl, json, os, pty, re, select, struct, sys, termios, time

PROJECT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', '..'))
# Cursor moves become line breaks — a redraw is a new line, not a continuation —; the rest goes.
MOVES = re.compile(r'\x1b\[[0-9;]*[HfABCDGJK]')
ANSI = re.compile(r'\x1b\[[0-9;?<>=]*[ -/]*[@-~]|\x1b\][^\x07]*\x07|\x1b[()][A-Z0-9]|\x1b[=>78]|\r')

args = sys.argv[1:]
real = '--real-model' in args
args = [a for a in args if a != '--real-model']
options = {}
for name in ('--cwd', '--before'):
    if name in args:
        i = args.index(name)
        options[name] = args[i + 1]
        del args[i:i + 2]
if len(args) != 1:
    sys.exit(__doc__)
steps = json.loads(args[0])
launch_dir = os.path.abspath(options.get('--cwd', PROJECT))

pid, fd = pty.fork()
if pid == 0:
    os.chdir(launch_dir)
    os.environ['TERM'] = 'xterm-256color'
    if not real:
        # .env.local carries a real key; an empty variable wins over it.
        os.environ['MISTRAL_API_KEY'] = ''
    os.execvp('php8.4', ['php8.4', os.path.join(PROJECT, 'bin/agentic'), 'chat'])

fcntl.ioctl(fd, termios.TIOCSWINSZ, struct.pack('HHHH', 50, 140, 0, 0))
raw = b''


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
    return True


def text():
    return ANSI.sub('', MOVES.sub('\n', raw.decode('utf-8', 'replace')))


def wait_for(pattern, timeout):
    end = time.time() + timeout
    while time.time() < end:
        if re.search(pattern, text()):
            return True
        if not pump(0.5):
            return False
    return False


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
    start = len(text())
    for ch in step['send']:
        os.write(fd, ch.encode())
        time.sleep(0.01)
    os.write(fd, b'\r')
    ok = wait_for(step['until'], step.get('timeout', 60))
    pump(step.get('settle', 2))
    failed = failed or not ok
    print('=== SENT: %r %s' % (step['send'], 'matched' if ok else 'TIMEOUT waiting for ' + step['until']))
    print('\n'.join(readable(text()[start:])[-80:]))

os.write(fd, b'\x03')
pump(3)
conversation = re.search(r'Conversation ([0-9a-f-]{36})', text())
print('=== conversation:', conversation.group(1) if conversation else '(not printed)')
sys.exit(1 if failed else 0)
