' Lanza el RIS Local Bridge SIN ventana visible.
' Espera a que el proceso termine y devuelve su codigo de salida, para que
' la Tarea Programada pueda reiniciarlo automaticamente si se cae.
Option Explicit

Dim shell, dir, bat, code
Set shell = CreateObject("WScript.Shell")

' Carpeta donde esta este .vbs (la misma del bridge).
dir = Left(WScript.ScriptFullName, InStrRev(WScript.ScriptFullName, "\"))
bat = dir & "start-bridge.bat"

shell.CurrentDirectory = dir

' 0 = ventana oculta ; True = esperar a que termine (mantiene la tarea "en ejecucion").
code = shell.Run("""" & bat & """", 0, True)

WScript.Quit code
