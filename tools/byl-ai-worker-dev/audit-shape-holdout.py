import sys,zipfile,xml.etree.ElementTree as E,re,json,random,subprocess
source,runner=sys.argv[1:];ns={'m':'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
z=zipfile.ZipFile(source)
strings=[''.join(t.text or '' for t in si.findall('.//m:t',ns)) for si in E.fromstring(z.read('xl/sharedStrings.xml')).findall('m:si',ns)] if 'xl/sharedStrings.xml' in z.namelist() else []
cols={'recipient_name':'P','country':'M','address_line_1':'Q','city':'R','province_state':'S','postal_code':'T','phone':'U','email':'V','recipient_customs_id':'O'}
eligible=[]
for row in E.fromstring(z.read('xl/worksheets/sheet1.xml')).findall('m:sheetData/m:row',ns)[1:]:
 r={}
 for c in row.findall('m:c',ns):
  col=re.sub(r'\d','',c.attrib['r']);v=c.find('m:v',ns);val=''.join(t.text or '' for t in c.findall('.//m:t',ns)) if v is None else v.text or '';r[col]=strings[int(val)] if c.get('t')=='s' and val else val
 if r.get('N','').strip() and sum(bool(r.get(c,'').strip()) for c in cols.values())>=4:
  eligible.append({'raw':r['N'],'expected':{k:r.get(c,'').strip() for k,c in cols.items() if r.get(c,'').strip()}})
excluded=set(random.Random(20261124).sample(range(len(eligible)),100))
pool=[i for i in range(len(eligible)) if i not in excluded]
sample=[eligible[i] for i in random.Random(20261125).sample(pool,100)]
result=subprocess.run(['/opt/homebrew/bin/node',runner],input=json.dumps(sample,ensure_ascii=False),text=True,capture_output=True,timeout=240)
if result.returncode!=0:raise SystemExit('local benchmark failed; no raw records printed')
aggregate=json.loads(result.stdout)
print(json.dumps(aggregate,ensure_ascii=False))
