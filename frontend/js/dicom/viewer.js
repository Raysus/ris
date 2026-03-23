function initViewer() {

    const element = document.getElementById("dicomViewer")

    cornerstone.enable(element)

    cornerstoneTools.init()

    cornerstoneTools.addTool(cornerstoneTools.ZoomTool)
    cornerstoneTools.addTool(cornerstoneTools.PanTool)
    cornerstoneTools.addTool(cornerstoneTools.WwwcTool)

    cornerstoneTools.setToolActive("Zoom", { mouseButtonMask: 2 })
    cornerstoneTools.setToolActive("Pan", { mouseButtonMask: 4 })
    cornerstoneTools.setToolActive("Wwwc", { mouseButtonMask: 1 })

}

function loadDicomImage(url) {
    const element = document.getElementById("dicomViewer")
    const imageId = "wadouri:" + url
    cornerstone.loadImage(imageId).then(function (image) {
        cornerstone.displayImage(element, image)
    })
}

function loadDicomStack(images) {
    const element = document.getElementById("dicomViewer")
    let imageIds = images.map(i => "wadouri:" + i)
    let stack = {
        currentImageIdIndex: 0,
        imageIds: imageIds
    }

    cornerstone.loadImage(imageIds[0]).then(function (image) {
        cornerstone.displayImage(element, image)
        cornerstoneTools.addStackStateManager(element, ["stack"])
        cornerstoneTools.addToolState(element, "stack", stack)
        cornerstoneTools.addTool(cornerstoneTools.StackScrollMouseWheelTool)
        cornerstoneTools.setToolActive("StackScrollMouseWheel", {})
    })

}

$("#toolZoom").click(() => {
    cornerstoneTools.setToolActive("Zoom", { mouseButtonMask: 1 })
})

$("#toolPan").click(() => {
    cornerstoneTools.setToolActive("Pan", { mouseButtonMask: 1 })
})

$("#toolWL").click(() => {
    cornerstoneTools.setToolActive("Wwwc", { mouseButtonMask: 1 })
})