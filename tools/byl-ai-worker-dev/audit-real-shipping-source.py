import sys,zipfile,xml.etree.ElementTree as E,re,json,random,hashlib,secrets,unicodedata,subprocess,collections
src,parser=sys.argv[1:];ns={'m':'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
z=zipfile.ZipFile(src)
strings=[''.join(t.text or '' for t in si.findall('.//m:t',ns)) for si in E.fromstring(z.read('xl/sharedStrings.xml')).findall('m:si',ns)] if 'xl/sharedStrings.xml' in z.namelist() else []
rows=[]
for row in E.fromstring(z.read('xl/worksheets/sheet1.xml')).findall('m:sheetData/m:row',ns)[1:]:
 r={}
 for c in row.findall('m:c',ns):
  col=re.sub(r'\d','',c.attrib['r']);v=c.find('m:v',ns);val=''.join(t.text or '' for t in c.findall('.//m:t',ns)) if v is None else v.text or '';r[col]=strings[int(val)] if c.get('t')=='s' and val else val
 rows.append(r)
cols={'recipient_name':'P','country':'M','address_line_1':'Q','city':'R','province_state':'S','postal_code':'T','phone':'U','email':'V','recipient_customs_id':'O'}
eligible=[r for r in rows if r.get('N','').strip() and sum(bool(r.get(c,'').strip()) for c in cols.values())>=4]
rng=random.Random(20261124); sample=rng.sample(eligible,100)
known=['full name','recipient name','name','first name','last name','nombre','nome','nombre completo','nome completo','country','país','pais','state','province','estado','provincia','city','ciudad','cidade','address line 1','address line','address','address street','street address','street','address line 2','address 2','complemento','dirección','direccion','endereço','endereco','post code','postal code','zip code','zipcode','zip','código postal','codigo postal','cep','email','e-mail','correo','correo electrónico','contact email','phone number','phone','teléfono','telefono','telefone','tel','tax id','tax number','rfc','cpf','code','coupon code','discount code','product','sku','username','handle','usuario','收件人','姓名','国家','州','省','城市','地址','地址2','邮编','邮箱','电话','清关税号','折扣码','产品','用户名']
prefix=re.compile(r'(?im)(?:^|[;\n]|(?<=\s))\s*('+ '|'.join(re.escape(x) for x in sorted(known,key=len,reverse=True))+r')(?=\s*[:：])')
word=re.compile(r'[^\W_]+',re.UNICODE)
counts=collections.Counter()
def norm(s):
 return ''.join(x for x in unicodedata.normalize('NFKD',str(s or '')).casefold() if x.isalnum())
def mask(s,key):
 def token(m):
  v=m.group(); digest=hashlib.sha256(key+v.casefold().encode()).digest()
  if v.isdecimal():return ''.join('0' if j==0 and a=='0' else str(digest[j%32]%10) for j,a in enumerate(v))
  return ''.join(chr(97+digest[j%32]%26) for j in range(len(v)))
 return word.sub(token,s)
def scrub(raw,key):
 ranges=[m.span(1) for m in prefix.finditer(raw)]
 out=[]; pos=0
 for a,b in ranges:
  out.extend([mask(raw[pos:a],key),raw[a:b]]);pos=b
 out.append(mask(raw[pos:],key));return ''.join(out)
records=[]
for r in sample:
 raw=r['N']; key=secrets.token_bytes(32); labels=[m.group(1).casefold() for m in prefix.finditer(raw)]
 counts.update(labels)
 expected={k:r.get(c,'').strip() for k,c in cols.items() if r.get(c,'').strip()}
 # Country labels may use different languages/codes; keep the raw audit independent,
 # and exclude deidentified country comparisons where tokens differ.
 red=scrub(raw,key)
 red_expected={k:mask(v,key) for k,v in expected.items()}
 records.append({'raw':raw,'red':red,'expected':expected,'red_expected':red_expected})
# Raw inputs and masked copies remain in local pipe memory; no file with values is written.
nodecode="""
import {parseKolQuickOrder as parse} from %s;
let raw='';for await(const c of process.stdin)raw+=c;
const rows=JSON.parse(raw);const out=rows.map(r=>[parse(r.raw),parse(r.red)]);
console.log(JSON.stringify(out));
"""%json.dumps('file://'+parser)
p=subprocess.run(['/opt/homebrew/bin/node','--input-type=module','-e',nodecode],input=json.dumps(records,ensure_ascii=False),text=True,capture_output=True,check=True)
parsed=json.loads(p.stdout)
metrics={mode:{k:collections.Counter() for k in cols} for mode in ['local_original','masked_original']}
complete=collections.Counter()
for row,pair in zip(records,parsed):
 for mode,draft,exp in [('local_original',pair[0],row['expected']),('masked_original',pair[1],row['red_expected'])]:
  ok=True
  for k,v in exp.items():
   m=metrics[mode][k];m['total']+=1;actual=draft.get(k)
   if not actual:m['missing']+=1;ok=False
   elif norm(actual)==norm(v):m['match']+=1
   else:m['different']+=1;ok=False
  if ok:complete[mode]+=1
# Only aggregates leave the device.
print(json.dumps({'source_rows':len(rows),'eligible':len(eligible),'sample_count':len(sample),'multiline':sum('\n' in r['N'] for r in sample),'labels':dict(counts.most_common(24)),'complete':dict(complete),'metrics':metrics},ensure_ascii=False))
