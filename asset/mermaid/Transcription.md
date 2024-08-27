zenuml
    title Transcription automatique des archives sonores
    @Actor Administrateur
    @Database Gallica
    @Database OmekaS
    @DataLab ChaoticumSeminario
    @DataProc Whisper
    Administrateur->Gallica."Téléchargement de l'archive"{
        return "archive locale"        
    }
    Administrateur->OmekaS."Ajout de l'archive"{
        ChaoticumSeminario."Transcription de l'archive"{
        while("Toutes les 50s") {
            ChaoticumSeminario->OmekaS: Création d'un fragment
            ChaoticumSeminario->Whisper."transcription du fragment"{
                return "Chronologie des concepts"
            }
            ChaoticumSeminario->OmekaS: Création d'une transcription                       
        }
    }