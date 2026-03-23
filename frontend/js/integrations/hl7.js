function generateHL7(study) {

    return `
MSH|^~\\&|RIS|RADIOLOGIA|HIS|HOSPITAL|${new Date().toISOString()}||ORU^R01|12345|P|2.3
PID|||${study.rut}||${study.patient}
OBR|||${study.study}
OBX|||Informe||${study.report}
`

}