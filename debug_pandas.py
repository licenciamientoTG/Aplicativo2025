import pandas as pd
import os

file_path = '_assets/temporal/statement nuevo.csv'
if os.path.exists(file_path):
    df = pd.read_csv(file_path, encoding='utf-8-sig')
    print("Columns found by Pandas:")
    print(df.columns.tolist())
    print("\nFirst row data keys:")
    print(df.iloc[0].to_dict().keys())
else:
    print("File not found")
