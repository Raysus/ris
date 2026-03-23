function sendToPACS(study) {
    console.log("Enviando a PACS", study)
    setTimeout(() => {
        showToast("Estudio enviado a PACS")
    }, 1500)
}