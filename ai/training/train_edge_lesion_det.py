from ultralytics import YOLO


def main():
    model = YOLO("yolo11n.pt")
    model.train(
        data=r"D:\Tooth-YoloV11\dataset_yolo_lesion_det\data.yaml",
        imgsz=320,
        epochs=200,
        batch=32,
        device=0,
        workers=0,
        patience=50,
        amp=True,
        seed=42,
        project=r"D:\Tooth-YoloV11\runs_edge",
        name="lesion_yolo11n_320",
    )


if __name__ == "__main__":
    main()
