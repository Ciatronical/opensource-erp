"""
Lokaler Fahrzeugscheinscanner (Zulassungsbescheinigung Teil I)
=============================================================

Pipeline: Foto/PDF -> Registrierung auf die Referenzvorlage (SIFT + RANSAC-Homographie,
pro Panel verfeinert) -> Feldausschnitte laut gelerntem Layout -> OCR je Ausschnitt
(RapidOCR, ONNX, CPU) -> Nachbearbeitung -> Ergebnis im Format der bisherigen API
(fahrzeugschein-scanner.de: Textfelder + <feld>_img Base64-Ausschnitte + document_img).
"""
__version__ = "1.0.0"
