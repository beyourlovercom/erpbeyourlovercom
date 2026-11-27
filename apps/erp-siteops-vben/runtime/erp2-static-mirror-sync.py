#!/usr/bin/env python3
"""ERP2: periodically mirror ONLY public Vben build artifacts from 007windows.
No business DB or credentials. Old index remains served if download/verification fails."""
from __future__ import annotations
import hashlib
import io
import os
import pathlib
import re
import shutil
import subprocess
import sys
import tempfile
import urllib.request
import zipfile

BASE = "http://100.99.21.57:18810"
DIR = pathlib.Path("/Users/a/ai/erp2-dev-ingress")
ROOT = DIR / "static-vben-20261123"
STATE = DIR / "static-vben-current-sha256"
URL_MANIFEST = BASE + "/erp2-static-mirror.sha256"
URL_PACKAGE = BASE + "/erp2-static-mirror-20261123.zip"
def read_url(url: str, timeout: int) -> bytes:
    req=urllib.request.Request(url,headers={"Cache-Control":"no-cache","User-Agent":"ERP2-Dev-Static-Mirror/1.0"})
    with urllib.request.urlopen(req, timeout=timeout) as response:
        if response.status != 200: raise RuntimeError("mirror origin returned non-200")
        return response.read()

def main():
    sha=read_url(URL_MANIFEST, 12).decode("ascii").strip().lower()
    if not re.fullmatch("[0-9a-f]{64}", sha): raise RuntimeError("invalid static package digest")
    if STATE.exists() and STATE.read_text().strip()==sha and (ROOT/"index.html").is_file():
        print("ERP2_STATIC_MIRROR_UNCHANGED sha="+sha[:12])
        return
    payload=read_url(URL_PACKAGE, 65)
    actual=hashlib.sha256(payload).hexdigest()
    if actual!=sha: raise RuntimeError("ERP2 mirror checksum mismatch")
    if len(payload)>30*1024*1024: raise RuntimeError("static package unexpectedly large")
    with tempfile.TemporaryDirectory(prefix=".erp2-static-stage-",dir=DIR) as temp:
        stage=pathlib.Path(temp)
        with zipfile.ZipFile(io.BytesIO(payload)) as archive:
            members=archive.infolist()
            if len(members)<100 or len(members)>3000:
                raise RuntimeError("unexpected static package file count")
            for member in members:
                raw=member.filename.replace("\\","/")
                pieces=pathlib.PurePosixPath(raw).parts
                if raw.startswith("/") or ".." in pieces or not pieces: raise RuntimeError("unsafe asset path")
                dest=stage.joinpath(*pieces)
                if member.is_dir(): dest.mkdir(parents=True,exist_ok=True);continue
                dest.parent.mkdir(parents=True,exist_ok=True)
                with archive.open(member) as src,dest.open("wb") as dst:
                    shutil.copyfileobj(src,dst)
        index=stage/"index.html"
        if not index.is_file() or not (stage/"jse").is_dir():
            raise RuntimeError("Vben index or JS entry missing")
        html=index.read_text(errors="replace")
        if "<title>BYL ERP SiteOps UAT</title>" not in html:
            raise RuntimeError("Vben site identity mismatch")
        script_refs=re.findall(r'(?:src|href)="(/(?:jse|js|css)/[^"]+)"',html)
        for ref in script_refs:
            if not (stage/ref.lstrip("/")).is_file():
                raise RuntimeError("missing index asset: "+ref[:90])
        ROOT.mkdir(parents=True,exist_ok=True,mode=0o750)
        # Keep old hashed files so open tabs never lose their imported JS chunks.
        proc=subprocess.run(["/usr/bin/rsync","-a","--exclude=index.html",str(stage)+"/",str(ROOT)+"/"],
          capture_output=True,text=True,timeout=40)
        if proc.returncode: raise RuntimeError("static rsync failed: "+proc.stderr[:150])
        pending=ROOT/".index.next"
        shutil.copyfile(index,pending)
        os.replace(pending,ROOT/"index.html") # update entrypoint only after assets
        tempstate=DIR/".static-sha-next"
        tempstate.write_text(sha+"\n")
        os.replace(tempstate,STATE)
        print(f"ERP2_STATIC_MIRROR_SYNC_PASS sha={sha[:12]} files={len(members)} bytes={len(payload)}")
if __name__=="__main__":
    try:main()
    except Exception as error:
        print("ERP2_STATIC_MIRROR_SYNC_HOLD "+str(error),file=sys.stderr)
        sys.exit(1)
