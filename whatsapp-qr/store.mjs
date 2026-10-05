import {readFile, writeFile, rename, mkdir} from 'node:fs/promises';
import {createCipheriv, createDecipheriv, randomBytes} from 'node:crypto';
export class Store {
  constructor(root, key, replacer, reviver) { this.root=root; this.key=Buffer.from(key,'hex'); this.replacer=replacer; this.reviver=reviver; this.queue=Promise.resolve(); }
  async read(name, fallback) {
    try {
      const b=await readFile(`${this.root}/${name}.enc`); const d=createDecipheriv('aes-256-gcm',this.key,b.subarray(0,12)); d.setAuthTag(b.subarray(12,28));
      return JSON.parse(Buffer.concat([d.update(b.subarray(28)),d.final()]).toString(),this.reviver);
    } catch(e) { if(e.code==='ENOENT')return fallback; throw e; }
  }
  write(name, value) {
    // Snapshot before queueing; async callers cannot mutate the stored decision afterwards.
    const json=JSON.stringify(value,this.replacer);
    const task=this.queue.then(async()=>{
      await mkdir(this.root,{recursive:true,mode:0o700}); const iv=randomBytes(12),c=createCipheriv('aes-256-gcm',this.key,iv);
      const data=Buffer.concat([c.update(json),c.final()]); const tmp=`${this.root}/${name}.tmp`;
      await writeFile(tmp,Buffer.concat([iv,c.getAuthTag(),data]),{mode:0o600}); await rename(tmp,`${this.root}/${name}.enc`);
    }); this.queue=task.catch(()=>{}); return task;
  }
}
export function phone(jid) { const p=String(jid||'').split('@'); return p[1]==='s.whatsapp.net' && /^[1-9][0-9]{7,14}$/.test(p[0].split(':')[0]) ? '+'+p[0].split(':')[0] : null; }
export function authorized(header,token) { return typeof header==='string' && header===`Bearer ${token}`; }

// QR pairing may persist me.id while registered stays false (phone-code flag).
export function hasLinkedSession(creds) { return creds?.registered === true || (typeof creds?.me?.id === 'string' && creds.me.id.length > 0); }
