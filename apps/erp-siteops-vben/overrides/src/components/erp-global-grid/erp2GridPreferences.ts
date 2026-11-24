/**
 * ERP2 transport adapter for ERP1's canonical user_ui_preferences controller.
 * No duplicate table, no custom preference database, and no credentials on disk.
 * The 007windows isolated gateway allowlists this endpoint on the Laravel Dev clone.
 */
const xsrfToken = (): string => {
  const item=document.cookie.split(';').map(x=>x.trim())
    .find(x=>x.startsWith('XSRF-TOKEN='));
  return item ? decodeURIComponent(item.slice('XSRF-TOKEN='.length)) : '';
};
const checked = async (response: Response): Promise<{ data: { value: unknown } }> => {
  if (!response.ok || !(response.headers.get('content-type')||'').includes('application/json'))
    throw new Error('ERP2_GRID_ACCOUNT_PREFERENCE_HTTP_'+response.status);
  const data:unknown=await response.json();
  if (!data || typeof data!=='object' || !('value' in data))
    throw new Error('ERP2_GRID_ACCOUNT_PREFERENCE_SCHEMA_INVALID');
  return {data:data as { value: unknown }};
};
export const erp2GridPreferences = {
  async get(path: string,_options?: {timeout?:number}) {
    const controller=new AbortController();
    const timeout=setTimeout(()=>controller.abort(),_options?.timeout??5000);
    try {
      const response=await fetch(path,{method:'GET',credentials:'same-origin',cache:'no-store',
        headers:{Accept:'application/json'},signal:controller.signal});
      return await checked(response);
    } finally {clearTimeout(timeout);}
  },
  async put(path:string,payload:{value:unknown}) {
    const token=xsrfToken();
    if (!token) throw new Error('ERP2_GRID_XSRF_TOKEN_MISSING');
    const response=await fetch(path,{method:'PUT',credentials:'same-origin',cache:'no-store',
      headers:{Accept:'application/json','Content-Type':'application/json','X-XSRF-TOKEN':token},
      body:JSON.stringify(payload)});
    return await checked(response);
  },
};
