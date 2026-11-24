// Queue preference writes by account + stable page id, including remounts.
const pending=new Map<string,Promise<void>>();
export function queuePreferenceWrite(key:string,write:()=>Promise<void>):Promise<void>{
  const previous=pending.get(key)??Promise.resolve();
  const next=previous.catch(()=>{}).then(write);
  pending.set(key,next);
  void next.finally(()=>{if(pending.get(key)===next)pending.delete(key)}).catch(()=>{});
  return next;
}
export async function waitForPreferenceWrites(key:string):Promise<void>{
  while(pending.has(key)) await pending.get(key)!.catch(()=>{});
}
