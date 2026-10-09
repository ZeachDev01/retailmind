import joblib
artifact = joblib.load('src/backend/storage/ml_models/random_forest_candidate_v20261009082757_4f75e5.joblib')
model = artifact['model']
# model is a Pipeline
print(model.named_steps.keys())
if 'preprocessor' in model.named_steps:
    print("Transformer features:", model.named_steps['preprocessor'].get_feature_names_out())
