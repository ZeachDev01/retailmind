import joblib
artifact = joblib.load('src/backend/storage/ml_models/random_forest_candidate_v20261009082757_4f75e5.joblib')
print(type(artifact))
if isinstance(artifact, dict):
    print(artifact.keys())
    model = artifact.get('model')
    print("Features expected:", artifact.get('features'))
