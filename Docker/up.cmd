@echo off
rem Installe et demarre SwitchBoard sous Windows (Docker Desktop). Options : voir up.ps1.
rem Ouvert depuis l'interieur d'un ZIP, Windows n'extrait que ce fichier : up.ps1 manque.
if not exist "%~dp0up.ps1" (
    echo Fichiers introuvables : extrayez d'abord le ZIP de SwitchBoard ^(clic droit ^> Extraire tout^),
    echo puis double-cliquez sur up.cmd dans le dossier Docker extrait.
    pause
    exit /b 1
)
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0up.ps1" %*
set CODE=%ERRORLEVEL%
if "%~1"=="" pause
exit /b %CODE%
