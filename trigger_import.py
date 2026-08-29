#!/usr/bin/env python3
"""
================================================================
  Li.Fe. Radio — Trigger giornaliero per import-rockol.php
  File: trigger_import.py
================================================================

Questo script risolve la challenge JavaScript di InfinityFree
(il cookie __test basato su AES) e poi richiama il tuo script
import-rockol.php sul server, facendogli importare le notizie
da Rockol.

COME SI USA:
  python3 trigger_import.py

Su GitHub Actions questo script gira automaticamente ogni giorno
(vedi il file .github/workflows/import-rockol.yml).

Non serve nessuna API key qui: la API key di scrape.do è già
dentro import-rockol.php sul server. Questo script fa solo da
"pulsante" che preme import-rockol.php ogni giorno.
================================================================
"""

import subprocess
import re
import sys
import urllib.parse

# ============ CONFIGURAZIONE ============
# Sostituisci con il tuo dominio reale (già compilato)
SITE_URL = "https://liferadio.rf.gd"
# La password del tuo admin panel (uguale a ACCESS_KEY in import-rockol.php)
ACCESS_KEY = "BARTOLO1"
# ========================================

# Percorso dello script PHP sul server
SCRIPT_URL = f"{SITE_URL}/api/import-radioitalia.php?key={ACCESS_KEY}"

# User-Agent da browser (ImportFree lo richiede)
USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
    "AppleWebKit/537.36 (KHTML, like Gecko) "
    "Chrome/120.0.0.0 Safari/537.36"
)


def http_get(url, extra_headers=None, timeout=30):
    """Esegue una richiesta GET con curl e restituisce (status_code, body)."""
    cmd = [
        "curl", "-sS", "-m", str(timeout),
        "-A", USER_AGENT,
        "-D", "-",  # stampa gli header
        "-o", "-",  # stampa il body
        "-w", "\n@@@HTTP_CODE:%{http_code}",
        url,
    ]
    if extra_headers:
        for k, v in extra_headers.items():
            cmd.extend(["-H", f"{k}: {v}"])
    result = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout + 10)
    output = result.stdout
    # Separa il body dal codice HTTP
    code = "000"
    if "@@@HTTP_CODE:" in output:
        body_part, code_part = output.rsplit("@@@HTTP_CODE:", 1)
        code = code_part.strip()
    else:
        body_part = output
    return code, body_part


def solve_infinityfree_challenge(html):
    """
    Risolve la challenge AES di InfinityFree.
    Il server restituisce una pagina con:
      var a = toNumbers("..."), b = toNumbers("..."), c = toNumbers("...");
      document.cookie = "__test=" + toHex(slowAES.decrypt(c, 2, a, b)) + ...
    slowAES.decrypt(c, 2, a, b) = AES-CBC con key=a, iv=b, ciphertext=c.
    Il cookie è l'HEX del decrypt GREZZO (senza rimuovere il padding).
    """
    # Prova a importare pycryptodome (PyCryptodome)
    try:
        from Crypto.Cipher import AES
    except ImportError:
        try:
            from Cryptodome.Cipher import AES
        except ImportError:
            print("ERRORE: serve la libreria pycryptodome.")
            print("  Installala con: pip install pycryptodome")
            sys.exit(1)

    # Estrai i valori a, b, c dalla pagina challenge
    m = re.search(
        r'toNumbers\("([0-9a-f]{32})"\)\s*,\s*b\s*=\s*toNumbers\("([0-9a-f]{32})"\)\s*,\s*c\s*=\s*toNumbers\("([0-9a-f]{32})"\)',
        html,
    )
    if not m:
        return None  # non è una pagina challenge

    a_hex = m.group(1)  # key (16 bytes)
    b_hex = m.group(2)  # IV  (16 bytes)
    c_hex = m.group(3)  # ciphertext (16 bytes)

    key = bytes.fromhex(a_hex)
    iv = bytes.fromhex(b_hex)
    ct = bytes.fromhex(c_hex)

    cipher = AES.new(key, AES.MODE_CBC, iv)
    decrypted = cipher.decrypt(ct)

    # Il cookie __test è l'HEX del decrypt GREZZO (NON rimuovere il padding)
    return decrypted.hex()


def run():
    print(f"=== Li.Fe. Radio — Trigger import Rockol ===")
    print(f"Target: {SCRIPT_URL}")
    print()

    # Step 1: prima richiesta → riceve la challenge JavaScript
    print("[1/3] Richiesta iniziale (per ottenere la challenge)...")
    code1, body1 = http_get(SCRIPT_URL)

    # Controlla se è una challenge
    cookie_val = solve_infinityfree_challenge(body1)
    if cookie_val is None:
        # Non è una challenge: potrebbe essere già la risposta o un errore
        if "Scaricata" in body1 or "Trovate" in body1 or "OK!" in body1:
            print("    Nessuna challenge rilevata. Lo script ha già risposto:")
            print(body1.strip())
            return True
        print(f"    Risposta imprevista (HTTP {code1}):")
        print(body1[:500])
        return False

    print(f"    Challenge rilevata. Cookie __test calcolato: {cookie_val}")

    # Step 2: seconda richiesta con il cookie → segue il redirect &i=1
    print("[2/3] Richiesta con cookie __test...")
    code2, body2 = http_get(
        SCRIPT_URL + "&i=1",
        extra_headers={"Cookie": f"__test={cookie_val}"},
        timeout=60,
    )

    # Se riceve di nuovo una challenge, riprova con &i=2
    if "toNumbers" in body2 and "aes.js" in body2:
        print("    Ancora challenge, riprovo con &i=2...")
        code2, body2 = http_get(
            SCRIPT_URL + "&i=2",
            extra_headers={"Cookie": f"__test={cookie_val}"},
            timeout=60,
        )

    # Step 3: verifica il risultato
    print("[3/3] Verifica risultato...")
    if "Scaricata" in body2 or "Trovate" in body2 or "OK!" in body2:
        print()
        print(">>> SUCCESSO! Risposta dello script PHP:")
        print(body2.strip())
        return True
    else:
        print()
        print(f">>> ERRORE. Risposta (HTTP {code2}):")
        print(body2[:500])
        return False


if __name__ == "__main__":
    ok = run()
    sys.exit(0 if ok else 1)
