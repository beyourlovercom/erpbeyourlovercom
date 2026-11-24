// Normalize only an explicitly supplied single supported profile URL.
// Ambiguous identities must be manually reviewed, never silently selected.
export function canonicalIdentity(text) {
 const hosts={'x.com':'twitter','twitter.com':'twitter','instagram.com':'instagram','tiktok.com':'tiktok','youtube.com':'youtube'};
 const candidates=new Map();
 for(const match of text.matchAll(/https?:\/\/[^\s<>"']+/gi)){
  try{
   const url=new URL(match[0].replace(/[.,;!?]+$/,''));
   const platform=hosts[url.hostname.toLowerCase().replace(/^www\./,'')];
   const path=url.pathname.split('/').filter(Boolean);
   if(!platform||path.length!==1)continue;
   const username=path[0].replace(/^@/,'');
   if(!/^[A-Za-z0-9_.-]{1,100}$/.test(username)||['home','explore','search','share','watch','reel','p','shorts'].includes(username.toLowerCase()))continue;
   candidates.set(platform+'\n'+username.toLowerCase(),{platform,username});
  }catch{}
 }
 return candidates.size===1?[...candidates.values()][0]:null;
}
