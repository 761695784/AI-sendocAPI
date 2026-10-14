@echo off
rem DocTrack - lecture des pieces (OCR) sur ce PC. Double-cliquez, laissez la fenetre ouverte.
rem Necessite Python 3.10, 3.11 ou 3.12 (pas 3.13/3.14) : https://www.python.org/downloads/release/python-31210/
cd /d "%~dp0"
set PY=
for %%V in (3.12 3.11 3.10) do if not defined PY (py -%%V -c "print(1)" >nul 2>&1 && set PY=py -%%V)
if not defined PY (
  echo.
  echo Python 3.12 est introuvable. Installez-le puis relancez ce fichier :
  echo https://www.python.org/downloads/release/python-31210/  ^(Windows installer 64-bit^)
  pause
  exit /b 1
)
if not exist .venv\Scripts\python.exe (
  echo Premiere installation ^(2 a 5 minutes^)...
  %PY% -m venv .venv || (pause & exit /b 1)
  .venv\Scripts\python -m pip install -q --upgrade pip
  .venv\Scripts\python -m pip install -q -r requirements.txt || (pause & exit /b 1)
)
echo.
echo OCR pret sur http://127.0.0.1:7860  ^(Ctrl+C pour arreter^)
.venv\Scripts\python -m uvicorn app:app --host 127.0.0.1 --port 7860
pause
