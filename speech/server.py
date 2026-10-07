"""Private, bounded Kokoro generation. No telephone or messaging credentials."""
import hashlib, hmac, json, os, re, subprocess, threading, time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

ROOT = Path(os.environ.get('SPEECH_STORAGE', '/data'))
VOICES = ('pf_dora', 'pm_alex', 'pm_santa')
TOKEN = os.environ.get('SPEECH_TOKEN', '')
MODEL_VERSION = 'kokoro-v1.0-onnx'
lock = threading.Lock()
engine = None

def path_for(workspace, identity):
    if not isinstance(workspace, int) or workspace < 1 or not re.fullmatch(r'[a-f0-9-]{36}', str(identity)):
        raise ValueError('Invalid identity')
    return ROOT / str(workspace) / identity

def write_state(path, data):
    tmp = path / 'state.tmp'
    tmp.write_text(json.dumps(data)); tmp.replace(path / 'state.json')

def generate(path, data):
    global engine
    try:
        import numpy as np
        import soundfile as sf
        import onnxruntime as rt
        rt.disable_telemetry_events()
        from kokoro_onnx import Kokoro
        started = time.monotonic()
        if engine is None:
            opts = rt.SessionOptions(); opts.intra_op_num_threads = 1; opts.inter_op_num_threads = 1
            session = rt.InferenceSession('/models/kokoro-v1.0.onnx', sess_options=opts, providers=['CPUExecutionProvider'])
            engine = Kokoro.from_session(session, '/models/voices-v1.0.bin')
        samples, rate = engine.create(data['text'], voice=data['voice'], speed=data['speed'], lang='pt-br')
        duration = len(samples) / rate
        if not 0.1 <= duration <= 120 or not np.isfinite(samples).all():
            raise ValueError('Audio duration or signal invalid')
        sf.write(path / 'original.wav', samples, rate, subtype='PCM_16')
        # Local file arguments only. No shell, remote URLs, or user-controlled codecs.
        for fmt, args in [('wav', ['-ar','8000','-ac','1','-c:a','pcm_s16le']), ('mp3',['-ar','24000','-ac','1','-c:a','libmp3lame','-b:a','64k']), ('ogg',['-ar','48000','-ac','1','-c:a','libopus','-b:a','32k'])]:
            subprocess.run(['ffmpeg','-hide_banner','-loglevel','error','-y','-i',str(path/'original.wav'),*args,str(path/f'audio.{fmt}')],check=True,timeout=25,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        (path/'original.wav').unlink()
        write_state(path, {'status':'ready','duration':round(duration,2),'generation_seconds':round(time.monotonic()-started,2),'model':MODEL_VERSION,'fingerprint':data['fingerprint']})
    except Exception:
        # Do not log personalized text or provider-style exception dumps.
        for f in path.glob('*.wav'): f.unlink(missing_ok=True)
        for f in path.glob('*.mp3'): f.unlink(missing_ok=True)
        for f in path.glob('*.ogg'): f.unlink(missing_ok=True)
        write_state(path, {'status':'failed','message':'Não foi possível gerar este áudio. Tente novamente.','fingerprint':data['fingerprint']})
    finally:
        lock.release()

class Handler(BaseHTTPRequestHandler):
    def log_message(self, *_): pass
    def reply(self, code, data):
        body=json.dumps(data).encode(); self.send_response(code); self.send_header('Content-Type','application/json'); self.send_header('Content-Length',str(len(body))); self.end_headers(); self.wfile.write(body)
    def auth(self):
        return bool(TOKEN) and hmac.compare_digest(self.headers.get('Authorization',''), 'Bearer '+TOKEN)
    def do_GET(self):
        if not self.auth(): return self.reply(401,{'error':'unauthorized'})
        if self.path=='/health': return self.reply(200,{'engine':MODEL_VERSION,'ready':Path('/models/kokoro-v1.0.onnx').exists(),'busy':lock.locked(),'voices':VOICES})
        self.reply(404,{'error':'not_found'})
    def do_POST(self):
        if not self.auth(): return self.reply(401,{'error':'unauthorized'})
        if self.path!='/generate': return self.reply(404,{'error':'not_found'})
        try:
            size=int(self.headers.get('Content-Length','0'))
            if not 0 < size <= 16384: return self.reply(413,{'error':'size'})
            self.connection.settimeout(5)
            d=json.loads(self.rfile.read(size))
            path=path_for(d['workspace_id'],d['id'])
            if not isinstance(d['text'],str) or not 1<=len(d['text'].strip())<=1500 or d['voice']not in VOICES or not 0.8<=float(d['speed'])<=1.2: raise ValueError()
            d['speed']=float(d['speed'])
            if not re.fullmatch('[a-f0-9]{64}',d['fingerprint']): raise ValueError()
            if (path/'state.json').exists():
                state=json.loads((path/'state.json').read_text())
                if state['fingerprint']!=d['fingerprint']:return self.reply(409,{'error':'identity_conflict'})
                return self.reply(200,state)
            if not lock.acquire(blocking=False):return self.reply(429,{'error':'busy'})
            try:
                if sum(f.stat().st_size for f in ROOT.rglob('audio.*'))>512*1024*1024: raise ValueError('quota')
                path.mkdir(parents=True,exist_ok=True,mode=0o700)
                write_state(path,{'status':'generating','fingerprint':d['fingerprint']})
                threading.Thread(target=generate,args=(path,d),daemon=True).start()
            except Exception:
                lock.release();raise
            self.reply(202,{'status':'generating'})
        except (ValueError,KeyError,TypeError):self.reply(422,{'error':'invalid_request'})
        except Exception:self.reply(503,{'error':'unavailable'})

if __name__=='__main__':
    if len(TOKEN)<32:raise SystemExit('Missing private service token')
    ROOT.mkdir(parents=True,exist_ok=True)
    for state in ROOT.glob('*/*/state.json'):
        try:
            d=json.loads(state.read_text())
            if d['status']=='generating':write_state(state.parent,{'status':'failed','fingerprint':d['fingerprint'],'message':'Geração interrompida; gere uma nova prévia.'})
        except (ValueError,KeyError):pass
    ThreadingHTTPServer(('0.0.0.0',8091),Handler).serve_forever()
