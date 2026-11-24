import {it,expect} from 'vitest';
import {queuePreferenceWrite,waitForPreferenceWrites} from './gridPreferenceQueue';
it('serializes same-account same-page writes, without blocking other pages/accounts',async()=>{
  const history:string[]=[];
  let release!:()=>void;
  const gate=new Promise<void>(r=>{release=r});
  const first=queuePreferenceWrite('1:review',async()=>{await gate;history.push('old')});
  const second=queuePreferenceWrite('1:review',async()=>{history.push('new')});
  await queuePreferenceWrite('1:pending',async()=>{history.push('pending')});
  await queuePreferenceWrite('2:review',async()=>{history.push('other')});
  expect(history).toEqual(['pending','other']);
  release(); await waitForPreferenceWrites('1:review');
  await Promise.all([first,second]);
  expect(history).toEqual(['pending','other','old','new']);
});
it('does not poison later writes after failure',async()=>{
  const a=queuePreferenceWrite('3:review',async()=>{throw Error('failed')});
  await expect(a).rejects.toThrow('failed');
  await expect(queuePreferenceWrite('3:review',async()=>{})).resolves.toBeUndefined();
});
