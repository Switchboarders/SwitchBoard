@echo off
rem Diagnostic de SwitchBoard sous Windows : produit un rapport (secrets masques) a coller dans une
rem conversation d'aide. Voir diagnostic.ps1.
if not exist "%~dp0diagnostic.ps1" (
    echo Fichiers introuvables : extrayez d'abord le ZIP de SwitchBoard ^(clic droit ^> Extraire tout^).
    pause
    exit /b 1
)
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0diagnostic.ps1" %*
set CODE=%ERRORLEVEL%
if "%~1"=="" pause
exit /b %CODE%
