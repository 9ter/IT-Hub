/**
 * Signature pads save the whole canvas (e.g. 600×200) even when the strokes cover a small part, so a printed
 * slot shrinks the ink to a few millimetres. For printing, crop the blank margin around the ink; the stored
 * signature is never changed. Results are cached per image, and `signaturesReady()` lets print code wait for them.
 */
const cache=new Map<string,Promise<string>>()
const pending=new Set<Promise<string>>()

function crop(src:string):Promise<string>{
  return new Promise(resolve=>{
    const img=new Image()
    img.onload=()=>{
      try{
        const w=img.naturalWidth,h=img.naturalHeight
        if(!w||!h){resolve(src);return}
        const canvas=document.createElement('canvas');canvas.width=w;canvas.height=h
        const ctx=canvas.getContext('2d',{willReadFrequently:true});if(!ctx){resolve(src);return}
        ctx.drawImage(img,0,0)
        const data=ctx.getImageData(0,0,w,h).data
        let top=h,left=w,right=-1,bottom=-1
        for(let y=0;y<h;y++)for(let x=0;x<w;x++){
          const i=(y*w+x)*4
          // Ink = visible and not near-white (pads may save a white or a transparent background).
          if(data[i+3]!>24&&(data[i]!<235||data[i+1]!<235||data[i+2]!<235)){if(x<left)left=x;if(x>right)right=x;if(y<top)top=y;if(y>bottom)bottom=y}
        }
        if(right<0){resolve(src);return}
        const pad=Math.max(4,Math.round(Math.max(right-left,bottom-top)*0.03))
        left=Math.max(0,left-pad);top=Math.max(0,top-pad);right=Math.min(w-1,right+pad);bottom=Math.min(h-1,bottom+pad)
        const cw=right-left+1,ch=bottom-top+1
        if(cw>=w*0.95&&ch>=h*0.95){resolve(src);return}
        const out=document.createElement('canvas');out.width=cw;out.height=ch
        out.getContext('2d')!.drawImage(canvas,left,top,cw,ch,0,0,cw,ch)
        resolve(out.toDataURL('image/png'))
      }catch{resolve(src)}
    }
    img.onerror=()=>resolve(src)
    img.src=src
  })
}

export function trimSignature(src:string):Promise<string>{
  let job=cache.get(src)
  if(!job){
    job=crop(src);cache.set(src,job)
    pending.add(job);void job.finally(()=>pending.delete(job!))
  }
  return job
}

/** Resolves once every crop started so far has finished (print code awaits this before window.print). */
export async function signaturesReady():Promise<void>{
  while(pending.size)await Promise.all([...pending])
}
