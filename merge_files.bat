@echo off
setlocal enabledelayedexpansion

:: Verzeichnis, das durchsucht werden soll
set "source_dir=%~1"
:: Ausgabe-Datei
set "output_file=%~2"

:: Standardwerte, falls keine Parameter angegeben werden
if "%source_dir%"=="" set "source_dir=."
if "%output_file%"=="" set "output_file=output.txt"

:: Ausgabe-Datei initialisieren mit dem gewünschten Text
(
    echo Projekt:Wordpress Plugin yadore-amazon-api
    echo Deine Rolle: Du hast diese Anwendung für mich programmiert. Bitte unterstütze mich bei der Optimierung und Erweiterung des Codes.
    echo Anforderungen:
    echo 1. Vollständige Dateien bereitstellen:
    echo - Wenn Änderungen an einer Datei notwendig sind, gib mir bitte stets die vollständige Datei zurück.
	echo 2. Sinnvolle Architektur bedenken:
	echo - Nicht zu viel Code in einer Datei, damit der Code gut gewartet werden kann.
) > "%output_file%"

:: Durchsuchen der Verzeichnisse nach .php und .js-Dateien
for /r "%source_dir%" %%f in (*.php *.js *.css) do (
    :: Pfad prüfen, ob er "plugin-update-checker" enthält
    echo %%f | findstr /i "\\includes\\plugin-update-checker\\" >nul
    if errorlevel 1 (
        echo Beginn des Codes %%~f >> "%output_file%"
        echo. >> "%output_file%"
        type "%%f" >> "%output_file%"
        echo. >> "%output_file%"
    )
)

echo Fertig! Die Dateien wurden in "%output_file%" zusammengefasst.
