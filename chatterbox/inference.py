"""Offline PT-BR generation using the official single-language model and reference.
Quantize transformer linear layers to int8 for bounded CPU memory. Each job exits,
releasing model memory. No runtime downloads or user-provided paths are accepted.
"""
import sys,json,gc,time,os
from pathlib import Path
sys.path.insert(0,'/opt/upstream/chatterbox/src')
import torch,numpy as np,soundfile as sf
from safetensors.torch import load_file
from chatterbox.tts import ChatterboxTTS,T3,T3ConfigMultilingual,VoiceEncoder,S3Gen,MTLTokenizer

def main(path):
 d=json.loads((path/'job.json').read_text());torch.set_num_threads(2);torch.set_num_interop_threads(1);torch.manual_seed(137400)
 from transformers.models.llama.modeling_llama import LlamaRotaryEmbedding
 config=T3ConfigMultilingual();config.text_tokens_dict_size=2454
 with torch.device('meta'):t3=T3(config)
 t3.load_state_dict(load_file('/models/t3_pt_br.safetensors'),assign=True)
 # Nonpersistent rotary buffers aren't included in checkpoints.
 for name,module in list(t3.named_modules()):
  if isinstance(module,LlamaRotaryEmbedding):
   parent,attr=name.rsplit('.',1);setattr(t3.get_submodule(parent),attr,LlamaRotaryEmbedding(config=t3.cfg,device='cpu'))
 assert not [n for n,b in t3.named_buffers() if b.is_meta]
 t3.eval();torch.ao.quantization.quantize_dynamic(t3.tfmr,{torch.nn.Linear},dtype=torch.qint8,inplace=True);gc.collect()
 print('Transformer loaded (CPU int8)',flush=True)
 ve=VoiceEncoder();ve.load_state_dict(torch.load('/models/ve.pt',weights_only=True,map_location='cpu',mmap=True),assign=True);ve.eval()
 s3=S3Gen();result=s3.load_state_dict(load_file('/models/s3gen_v3.safetensors'),strict=False,assign=True);s3.eval()
 # Upstream intentionally leaves generated mel/window buffers out of the checkpoint.
 assert not result.unexpected_keys,result.unexpected_keys
 assert all(k.endswith(('_mel_filters','window')) for k in result.missing_keys),result.missing_keys
 model=ChatterboxTTS(t3,s3,ve,MTLTokenizer('/models/grapheme_mtl_merged_expanded_v1.json'),'cpu')
 print('Decoder loaded',flush=True)
 with torch.inference_mode():wav=model.generate(d['text'],language_id='pt',audio_prompt_path='/models/pt_br_f2.wav',exaggeration=.5,cfg_weight=.5,temperature=.8)
 samples=wav.squeeze(0).cpu().numpy();assert np.isfinite(samples).all() and .1<len(samples)/model.sr<=45
 sf.write(path/'original.wav',samples,model.sr,subtype='PCM_16')
 print('Audio generated',round(len(samples)/model.sr,2),flush=True)
if __name__=='__main__':main(Path(sys.argv[1]))
