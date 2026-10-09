import joblib
artifact = joblib.load('src/backend/storage/ml_models/random_forest_candidate_v20261009082757_4f75e5.joblib')
print(artifact['metadata'])
model = artifact['model']
print(hasattr(model, 'feature_names_in_'))
if hasattr(model, 'feature_names_in_'):
    print(model.feature_names_in_)
